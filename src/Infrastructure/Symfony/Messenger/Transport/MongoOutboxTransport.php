<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Transport;

use App\Application\Shared\Port\ClockInterface;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Infrastructure\Persistence\Mongo\Outbox\DomainEventOutbox;
use App\Infrastructure\Persistence\Mongo\Outbox\OutboxRecord;
use DateTimeImmutable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Transport Messenger adosse a la collection `domain_event_outbox`.
 *
 * Ecrit maison parce qu'aucun transport livre ne convient : `doctrine://` suppose une base
 * relationnelle ; `amqp://` suppose un broker absent ; et `redis://` detournerait le Redis
 * dedie au cache de queries en broker. Surtout, aucun de ces transports ne peut inscrire le
 * message dans la transaction MongoDB qui persiste l'agregat. Voir `MongoDomainEventBus` pour
 * ce point.
 *
 * Cette classe ne fait que traduire : `OutboxRecord` ↔ `Envelope`. Tout l'acces a la base
 * vit dans `DomainEventOutbox`, ce qui garde Doctrine hors de `Symfony/`.
 */
final readonly class MongoOutboxTransport implements TransportInterface, MessageCountAwareInterface, ListableReceiverInterface
{
    public function __construct(
        private DomainEventOutbox $outbox,
        private SerializerInterface $serializer,
        private ClockInterface $clock,
        private string $queueName,
        /**
         * Delai au-dela duquel un message reserve est considere abandonne — worker tue,
         * conteneur recycle — et redevient eligible.
         */
        private int $redeliverTimeout = 3600,
    ) {
    }

    public function get(): iterable
    {
        $record = $this->outbox->claim($this->queueName, $this->redeliverTimeout);

        return null === $record ? [] : [$this->toEnvelope($record)];
    }

    public function ack(Envelope $envelope): void
    {
        $this->outbox->remove($this->receivedId($envelope));
    }

    /**
     * Identique a `ack()` : la copie vers la file d'echec est faite en amont par
     * `SendFailedMessageToFailureTransportListener`, qui `send()` sur l'autre file. Garder
     * la ligne ici la ferait redelivrer indefiniment apres le `redeliver_timeout`.
     */
    public function reject(Envelope $envelope): void
    {
        $this->outbox->remove($this->receivedId($envelope));
    }

    /**
     * Sur ce transport, `send()` n'est **pas** le chemin nominal : les Domain Events sont
     * ecrits par `MongoDomainEventBus`, dans la transaction de l'agregat. Il ne sert qu'aux
     * chemins de reprise — un retry differe, une copie vers la file d'echec, un
     * `messenger:failed:retry` — qui s'executent tous hors transaction metier, et pour
     * lesquels le message doit etre durable immediatement. D'ou `enqueueAndFlush()`, qui
     * n'aurait aucun sens dans le chemin nominal.
     */
    public function send(Envelope $envelope): Envelope
    {
        $encoded = $this->serializer->encode($envelope);
        $message = $envelope->getMessage();

        $id = $this->outbox->enqueueAndFlush(
            queueName: $this->queueName,
            body: $encoded['body'],
            headers: $encoded['headers'] ?? [],
            event: $message instanceof DomainEventInterface ? $message : null,
            availableAt: $this->availableAt($envelope),
        );

        return $envelope->with(new TransportMessageIdStamp($id));
    }

    public function getMessageCount(): int
    {
        return $this->outbox->count($this->queueName);
    }

    public function all(?int $limit = null): iterable
    {
        foreach ($this->outbox->all($this->queueName, $limit) as $record) {
            yield $this->toEnvelope($record);
        }
    }

    public function find(mixed $id): ?Envelope
    {
        if (!is_string($id)) {
            return null;
        }

        $record = $this->outbox->find($this->queueName, $id);

        return null === $record ? null : $this->toEnvelope($record);
    }

    private function toEnvelope(OutboxRecord $record): Envelope
    {
        try {
            $envelope = $this->serializer->decode([
                'body' => $record->body,
                'headers' => $record->headers,
            ]);
        } catch (MessageDecodingFailedException $exception) {
            // Un corps illisible ne se repare pas au retry : il empoisonnerait la file en
            // revenant a chaque `redeliver_timeout`. On le retire avant de propager.
            $this->outbox->remove($record->id);

            throw $exception;
        }

        return $envelope->with(
            new MongoOutboxReceivedStamp($record->id),
            new TransportMessageIdStamp($record->id),
        );
    }

    private function availableAt(Envelope $envelope): ?DateTimeImmutable
    {
        $delay = $envelope->last(DelayStamp::class);

        if (!$delay instanceof DelayStamp) {
            return null;
        }

        return $this->clock->now()->modify(sprintf('+%d milliseconds', $delay->getDelay()));
    }

    private function receivedId(Envelope $envelope): string
    {
        $stamp = $envelope->last(MongoOutboxReceivedStamp::class);

        if (!$stamp instanceof MongoOutboxReceivedStamp) {
            throw new LogicException('No MongoOutboxReceivedStamp found on the Envelope.');
        }

        return $stamp->getId();
    }
}
