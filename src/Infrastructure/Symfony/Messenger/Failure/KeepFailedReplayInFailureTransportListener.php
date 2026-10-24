<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Failure;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Un message rejoué depuis une file d'échec, qui échoue de nouveau, **retourne dans cette file**.
 *
 * Sans lui, Messenger le perd. Un message lu depuis une file d'échec part, s'il échoue sans être
 * réessayé, dans la file d'échec **de cette file** — la file globale, `failed_domain_events`, faute
 * de mieux. Et quand cette file est la sienne, `SendFailedMessageToFailureTransportListener` renonce
 * (`failureTransportsByName[...] === receiverName`), puis le worker le retire : **supprimé**. Vérifié
 * le 2026-10-23 : rejoué depuis `failed_domain_events`, un message toujours en échec a disparu. Depuis
 * `failed_user_events`, il atterrissait dans `failed_domain_events`, où un second rejeu raté l'aurait
 * effacé à son tour.
 *
 * ## Comment il reconnaît un rejeu
 *
 * Tout message rangé dans une file d'échec porte un `SentToFailureTransportStamp`. Un échec sur un
 * message qui le porte est donc un rejeu raté, quelle que soit la file : la règle couvre
 * `failed_user_events`, `failed_domain_events` et `failed_async` sans liste à tenir.
 *
 * ## Sa place parmi les écouteurs de Messenger
 *
 * Priorité -50 : **après** `SendFailedMessageForRetryListener` (100), pour savoir si un retry est
 * prévu — auquel cas il n'a rien à faire, le message reste déjà dans sa file ; **avant**
 * `SendFailedMessageToFailureTransportListener` (-100), qu'il neutralise en posant un
 * `SentToFailureTransportStamp` au nom de la file lue : celui-ci renonce alors à son propre envoi, qui
 * aurait fait un doublon dans la file globale.
 *
 * Le message renvoyé garde son `SentToFailureTransportStamp` d'origine — `messenger:failed:show`
 * continue d'afficher le transport d'où il venait — et l'erreur du dernier essai, déjà ajoutée par
 * `AddErrorDetailsStampListener` (200). Le transport des files d'échec écrit un nouvel enregistrement
 * à chaque envoi : la copie existe avant que le worker ne retire l'original.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -50)]
final readonly class KeepFailedReplayInFailureTransportListener
{
    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $transports,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if ($event->willRetry() || null === $envelope->last(SentToFailureTransportStamp::class)) {
            return;
        }

        $failureTransportName = $event->getReceiverName();
        $failureTransport = $this->transports->get($failureTransportName);

        if (!$failureTransport instanceof SenderInterface) {
            return;
        }

        $failureTransport->send($envelope->with(new DelayStamp(0), new RedeliveryStamp(0)));

        // Neutralise l'envoi de Messenger vers la file globale : il compare ce nom au transport lu.
        $event->addStamps(new SentToFailureTransportStamp($failureTransportName));

        $this->logger->warning('Replayed message {class} failed again and was kept in {transport}.', [
            'class' => $envelope->getMessage()::class,
            'transport' => $failureTransportName,
        ]);
    }
}
