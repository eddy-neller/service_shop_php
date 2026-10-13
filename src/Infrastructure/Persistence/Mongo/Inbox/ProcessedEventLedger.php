<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Inbox;

use App\Application\Shared\Port\ClockInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Collection;

/**
 * Inbox portee par la collection `processed_event`.
 *
 * Seul endroit de l'inbox qui connaisse Doctrine, comme `DomainEventOutbox` l'est pour l'outbox :
 * les handlers ne dependent que de `ProcessedEventLedgerInterface`.
 *
 * L'ecriture passe par `persist()` et **ne flushe pas** : sa durabilite est celle de l'appelant,
 * donc, dans un callback `TransactionalInterface`, le commit de l'ecriture metier. Un `insertOne()`
 * direct n'aurait pas la session de la transaction et survivrait a son rollback, sans lever la
 * moindre erreur.
 *
 * La lecture, elle, passe par le pilote : c'est une simple interrogation, hors unite de travail.
 */
final readonly class ProcessedEventLedger implements ProcessedEventLedgerInterface
{
    public function __construct(
        private DocumentManager $documentManager,
        private ClockInterface $clock,
    ) {
    }

    public function hasProcessed(string $eventId, string $handler): bool
    {
        return null !== $this->collection()->findOne(
            ['eventId' => $eventId, 'handler' => $handler],
            ['projection' => ['_id' => 1]],
        );
    }

    public function markProcessed(string $eventId, string $handler, ProcessedEventSource $source): void
    {
        $now = $this->clock->now();

        $document = new ProcessedEventDocument();
        $document->eventId = $eventId;
        $document->handler = $handler;
        $document->source = $source->value;
        $document->processedAt = $now;
        $document->expiresAt = $this->expiryOf($now, $source);

        // `persist()` et non le pilote : l'ecriture rejoint l'unite de travail de l'appelant, donc
        // son `flush(['withTransaction' => true])`. Un `insertOne()` direct n'aurait pas la session
        // de la transaction et casserait l'atomicite sans lever la moindre erreur.
        $this->documentManager->persist($document);
    }

    private function expiryOf(DateTimeImmutable $now, ProcessedEventSource $source): DateTimeImmutable
    {
        return $now->modify(sprintf('+%d days', $source->retentionDays()));
    }

    private function collection(): Collection
    {
        return $this->documentManager
            ->getDocumentCollection(ProcessedEventDocument::class);
    }
}
