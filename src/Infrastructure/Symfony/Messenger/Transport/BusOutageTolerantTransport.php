<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Transport;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Exception\Http\NetworkException;
use AsyncAws\Core\Exception\Http\ServerException;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Garde le consommateur de la file `user_events` en vie quand le bus est injoignable.
 *
 * `Worker::run()` appelle `$receiver->get()` **sans le protéger** : une file SQS injoignable faisait
 * donc mourir `messenger:consume user_events` à chaque essai. Supervisor le relançait, mais une
 * commande qui meurt avant `startsecs` compte comme un démarrage raté : au bout de `startretries`,
 * soit une vingtaine de minutes de panne, il la passait `FATAL`, et seul un
 * `docker compose restart worker` la remettait en route. Une panne temporaire du bus devenait un
 * arrêt définitif de la consommation.
 *
 * Ce décorateur reconnaît l'indisponibilité, journalise, **attend** — 1 s, doublé à chaque échec,
 * plafonné à 30 s — puis rend « aucun message ». Le worker reste en vie, reprend la file dès le retour
 * du bus, et le fait quel que soit le gestionnaire de processus : Supervisor aujourd'hui, Kubernetes
 * demain, où la même panne aurait produit un `CrashLoopBackOff`.
 *
 * ## Ce qui compte comme « injoignable »
 *
 * La même définition que l'émetteur : le réseau (`NetworkException`, hôte introuvable ou connexion
 * refusée), une erreur 5xx (`ServerException`), et le throttling, en 429 ou en 400 avec un code
 * d'étranglement. AsyncAws les a déjà réessayées en interne quand elles arrivent ici.
 *
 * **Tout le reste remonte tel quel.** Une file inexistante, des droits refusés : attendre ne les
 * résoudra pas, et le processus doit mourir bruyamment. Une `MessageDecodingFailedException` remonte
 * aussi : le sérialiseur ne la lève plus que pour un renvoi de retry illisible, écrit par ce service
 * lui-même — un corps qui viole le contrat, lui, est conservé sans jamais lever.
 *
 * ## Le récepteur SQS est un générateur
 *
 * `AmazonSqsReceiver::get()` contient un `yield` : l'appeler ne fait **rien**, la requête part quand
 * le worker itère le résultat. Un `try/catch` autour du seul appel n'attraperait donc aucune erreur.
 * D'où `iterator_to_array()` : la réception a lieu ici, dans le `try`. Le récepteur ne rend qu'une
 * enveloppe par appel, le matérialiser ne coûte rien.
 *
 * ## Il reprend toutes les capacités du transport
 *
 * `AmazonSqsTransport` est aussi `MessageCountAware`, `Keepalive`, `Setupable`, `Closeable` et
 * `Reset`. Messenger active chacune de ces fonctions par `instanceof` sur le transport : un
 * décorateur qui en oublierait une la ferait disparaître **sans erreur** (`messenger:stats` cesserait
 * de lister la file). Chacune délègue donc au transport décoré.
 */
#[AsDecorator(decorates: 'messenger.transport.user_events')]
final class BusOutageTolerantTransport implements TransportInterface, KeepaliveReceiverInterface, SetupableTransportInterface, CloseableTransportInterface, MessageCountAwareInterface, ResetInterface
{
    private const int MAX_WAIT_SECONDS = 30;

    /** Codes AWS d'étranglement, renvoyés en HTTP 400. */
    private const array THROTTLING_CODES = ['Throttling', 'ThrottlingException', 'RequestThrottled', 'RequestLimitExceeded'];

    private int $failedAttempts = 0;

    public function __construct(
        #[AutowireDecorated]
        private readonly TransportInterface $inner,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(): iterable
    {
        try {
            $envelopes = iterator_to_array($this->inner->get(), false);
        } catch (TransportException $exception) {
            if (!$this->isUnavailability($exception)) {
                throw $exception;
            }

            $this->waitBeforeNextAttempt($exception);

            return [];
        }

        if (0 < $this->failedAttempts) {
            $this->logger->info('Integration bus reachable again after {attempts} failed attempts.', [
                'attempts' => $this->failedAttempts,
            ]);
            $this->failedAttempts = 0;
        }

        return $envelopes;
    }

    public function ack(Envelope $envelope): void
    {
        $this->inner->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->inner->reject($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->inner->send($envelope);
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        if ($this->inner instanceof KeepaliveReceiverInterface) {
            $this->inner->keepalive($envelope, $seconds);
        }
    }

    public function setup(): void
    {
        if ($this->inner instanceof SetupableTransportInterface) {
            $this->inner->setup();
        }
    }

    public function close(): void
    {
        if ($this->inner instanceof CloseableTransportInterface) {
            $this->inner->close();
        }
    }

    public function getMessageCount(): int
    {
        if (!$this->inner instanceof MessageCountAwareInterface) {
            throw new LogicException(sprintf('The decorated transport %s cannot count its messages.', $this->inner::class));
        }

        return $this->inner->getMessageCount();
    }

    public function reset(): void
    {
        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    private function waitBeforeNextAttempt(TransportException $exception): void
    {
        ++$this->failedAttempts;
        $seconds = min(2 ** ($this->failedAttempts - 1), self::MAX_WAIT_SECONDS);

        $this->logger->warning('Integration bus unreachable, the consumer waits {seconds} s before attempt {attempt}.', [
            'seconds' => $seconds,
            'attempt' => $this->failedAttempts + 1,
            'exception' => $exception,
        ]);

        $this->clock->sleep($seconds);
    }

    private function isUnavailability(TransportException $exception): bool
    {
        $cause = $exception->getPrevious();

        if ($cause instanceof NetworkException || $cause instanceof ServerException) {
            return true;
        }

        return $cause instanceof ClientException
            && (429 === $cause->getResponse()->getStatusCode() || in_array($cause->getAwsCode(), self::THROTTLING_CODES, true));
    }
}
