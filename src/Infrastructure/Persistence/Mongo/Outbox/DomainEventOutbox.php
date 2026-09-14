<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Outbox;

use App\Application\Shared\Port\ClockInterface;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

/**
 * Acces a la collection `domain_event_outbox`.
 *
 * Seul endroit de l'outbox qui connaisse Doctrine : `MongoDomainEventBus` et
 * `MongoOutboxTransport` passent par ici, ce qui maintient la regle « aucune reference a
 * Doctrine hors `Persistence/` ». Ils ne manipulent que des chaines deja serialisees.
 *
 * Deux ecritures, et la difference entre elles est toute l'affaire :
 *
 * - `enqueue()` fait `persist()` et **ne flushe pas**. La durabilite est celle de l'appelant :
 *   dans un callback `TransactionalInterface`, c'est le commit de l'agregat. C'est le chemin
 *   nominal, et c'est ce qui rend l'outbox transactionnel.
 * - `enqueueAndFlush()` engage tout de suite. Reserve aux chemins de reprise, qui s'executent
 *   hors transaction metier et pour lesquels le message doit exister immediatement.
 *
 * Les lectures et l'acquittement passent par le pilote, pas par l'unite de travail : reserver
 * un message est un `findOneAndUpdate` atomique, que l'ODM ne sait pas exprimer.
 */
final readonly class DomainEventOutbox
{
    public const string DEFAULT_QUEUE = 'domain_events';

    /** @var array{root: string, document: string, array: string} */
    private const array TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(
        private DocumentManager $documentManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Ajoute la ligne au flush courant, sans le declencher.
     *
     * @param array<string, string> $headers
     */
    public function enqueue(
        string $queueName,
        string $body,
        array $headers,
        ?DomainEventInterface $event = null,
        ?DateTimeImmutable $availableAt = null,
    ): DomainEventDocument {
        $document = $this->build($queueName, $body, $headers, $event, $availableAt);

        $this->documentManager->persist($document);

        return $document;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return string identifiant de la ligne ecrite
     */
    public function enqueueAndFlush(
        string $queueName,
        string $body,
        array $headers,
        ?DomainEventInterface $event = null,
        ?DateTimeImmutable $availableAt = null,
    ): string {
        $document = $this->enqueue($queueName, $body, $headers, $event, $availableAt);

        $this->documentManager->flush();

        return (string) $document->id;
    }

    /**
     * Reserve le plus ancien message eligible et le rend, ou `null` si la file est vide.
     *
     * Un message deja reserve redevient eligible passe `$redeliverTimeout` : c'est ce qui
     * rattrape un worker tue en plein traitement et rend la livraison au-moins-une-fois.
     */
    public function claim(string $queueName, int $redeliverTimeout): ?OutboxRecord
    {
        $now = $this->clock->now();
        $abandonedBefore = $now->modify(sprintf('-%d seconds', $redeliverTimeout));

        $document = $this->collection()->findOneAndUpdate(
            [
                'queueName' => $queueName,
                'availableAt' => ['$lte' => new UTCDateTime($now)],
                '$or' => [
                    ['deliveredAt' => null],
                    ['deliveredAt' => ['$lte' => new UTCDateTime($abandonedBefore)]],
                ],
            ],
            ['$set' => ['deliveredAt' => new UTCDateTime($now)]],
            [
                // Le plus ancien eligible d'abord : l'ordre de publication est conserve tant
                // qu'aucun retry n'a repousse un message.
                'sort' => ['availableAt' => 1],
                'typeMap' => self::TYPE_MAP,
            ],
        );

        return is_array($document) ? $this->toRecord($document) : null;
    }

    public function remove(string $id): void
    {
        $this->collection()->deleteOne(['_id' => new ObjectId($id)]);
    }

    public function count(string $queueName): int
    {
        return $this->collection()->countDocuments(['queueName' => $queueName]);
    }

    /**
     * @return iterable<OutboxRecord>
     */
    public function all(string $queueName, ?int $limit = null): iterable
    {
        $options = ['sort' => ['availableAt' => 1], 'typeMap' => self::TYPE_MAP];

        if (null !== $limit) {
            $options['limit'] = $limit;
        }

        foreach ($this->collection()->find(['queueName' => $queueName], $options) as $document) {
            if (is_array($document)) {
                yield $this->toRecord($document);
            }
        }
    }

    public function find(string $queueName, string $id): ?OutboxRecord
    {
        $document = $this->collection()->findOne(
            ['_id' => new ObjectId($id), 'queueName' => $queueName],
            ['typeMap' => self::TYPE_MAP],
        );

        return is_array($document) ? $this->toRecord($document) : null;
    }

    /**
     * @param array<string, string> $headers
     */
    private function build(
        string $queueName,
        string $body,
        array $headers,
        ?DomainEventInterface $event,
        ?DateTimeImmutable $availableAt,
    ): DomainEventDocument {
        $now = $this->clock->now();

        $document = new DomainEventDocument();
        $document->queueName = $queueName;
        $document->body = $body;
        $document->headers = $headers;
        $document->createdAt = $now;
        $document->availableAt = $availableAt ?? $now;

        // Recopie de l'identite en clair a cote du blob serialise : elle ne sert pas au
        // transport, seulement a lire la collection dans `mongosh` sans deserialiser du PHP.
        if (null !== $event) {
            $document->eventName = $event->eventName();
            $document->eventId = $event->eventId();
            $document->aggregateId = $event->aggregateId();
            $document->occurredOn = $event->occurredOn();
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function toRecord(array $document): OutboxRecord
    {
        $headers = $document['headers'] ?? [];

        return new OutboxRecord(
            id: (string) $document['_id'],
            body: (string) $document['body'],
            headers: is_array($headers) ? array_map(strval(...), $headers) : [],
        );
    }

    private function collection(): Collection
    {
        return $this->documentManager->getDocumentCollection(DomainEventDocument::class);
    }
}
