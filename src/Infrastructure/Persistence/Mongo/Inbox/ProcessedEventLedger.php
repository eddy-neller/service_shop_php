<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Inbox;

use App\Application\Shared\Port\ClockInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Inbox portee par la collection `processed_event`.
 *
 * Seul endroit de l'inbox qui connaisse Doctrine, comme `DomainEventOutbox` l'est pour l'outbox :
 * les handlers ne dependent que de `ProcessedEventLedgerInterface`.
 *
 * Les deux operations passent par le **pilote** et non par l'unite de travail : le marquage doit
 * etre engage immediatement, hors de tout flush metier, puisqu'il suit un effet deja produit.
 * C'est aussi ce qui le rend utilisable depuis un handler qui n'ouvre aucune transaction.
 */
final readonly class ProcessedEventLedger implements ProcessedEventLedgerInterface
{
    private const int DUPLICATE_KEY = 11000;

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
        $expiresAt = $now->modify(sprintf('+%d days', $source->retentionDays()));

        try {
            // `$setOnInsert` : un second marquage ne doit ni repousser l'expiration ni reecrire
            // la date de traitement — la premiere reussite est la verite.
            $this->collection()->updateOne(
                ['eventId' => $eventId, 'handler' => $handler],
                ['$setOnInsert' => [
                    'eventId' => $eventId,
                    'handler' => $handler,
                    'source' => $source->value,
                    'processedAt' => new UTCDateTime($now),
                    'expiresAt' => new UTCDateTime($expiresAt),
                ]],
                ['upsert' => true],
            );
        } catch (BulkWriteException $exception) {
            // Deux workers ont insere la meme cle au meme instant : l'upsert perdant est rejete
            // par l'index unique. La ligne existe, donc le resultat voulu est atteint — toute
            // autre erreur d'ecriture, elle, doit continuer de remonter.
            if (self::DUPLICATE_KEY !== $exception->getCode()) {
                throw $exception;
            }
        }
    }

    private function collection(): Collection
    {
        return $this->documentManager
            ->getDocumentCollection(ProcessedEventDocument::class);
    }
}
