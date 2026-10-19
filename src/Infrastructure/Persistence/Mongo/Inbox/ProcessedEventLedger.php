<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Inbox;

use App\Application\Shared\Port\ClockInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Collection;

/**
 * Inbox portee par la collection `processed_event`.
 *
 * Seul endroit de l'inbox qui connaisse Doctrine, comme `DomainEventOutbox` l'est pour l'outbox :
 * les handlers ne dependent que de `ProcessedEventLedgerInterface`.
 *
 * `markProcessed()` passe par `persist()` et **ne flushe pas** : sa durabilite est celle de
 * l'appelant, donc, dans un callback `TransactionalInterface`, le commit de l'ecriture metier. Un
 * `insertOne()` direct n'aurait pas la session de la transaction et survivrait a son rollback, sans
 * lever la moindre erreur.
 *
 * `markProcessedAndFlush()` engage tout de suite, pour les handlers qui ne detiennent pas la
 * transaction de leur effet. Meme decoupage que l'outbox et son `enqueueAndFlush()`.
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
        // `persist()` et non le pilote : l'ecriture rejoint l'unite de travail de l'appelant, donc
        // son `flush(['withTransaction' => true])`. Un `insertOne()` direct n'aurait pas la session
        // de la transaction et casserait l'atomicite sans lever la moindre erreur.
        $this->documentManager->persist($this->newDocument($eventId, $handler, $source));
    }

    public function markProcessedAndFlush(string $eventId, string $handler, ProcessedEventSource $source): void
    {
        $this->markProcessed($eventId, $handler, $source);

        // Appele apres un effet deja commite — ou externe, donc hors de toute transaction : la
        // ligne doit exister des maintenant, sans quoi elle n'existerait jamais.
        $this->documentManager->flush();
    }

    private function newDocument(
        string $eventId,
        string $handler,
        ProcessedEventSource $source,
    ): ProcessedEventDocument {
        $now = $this->clock->now();

        $document = new ProcessedEventDocument();
        $document->eventId = $eventId;
        $document->handler = $handler;
        $document->source = $source->value;
        $document->processedAt = $now;
        $document->expiresAt = $now->modify(sprintf('+%d days', $source->retentionDays()));

        return $document;
    }

    private function collection(): Collection
    {
        return $this->documentManager
            ->getDocumentCollection(ProcessedEventDocument::class);
    }
}
