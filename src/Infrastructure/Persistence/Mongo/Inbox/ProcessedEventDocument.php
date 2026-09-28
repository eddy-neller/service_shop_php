<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Inbox;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Attribute as MongoDB;

/**
 * Une ligne de l'inbox : cet evenement a deja ete traite par ce handler.
 *
 * L'index unique `(eventId, handler)` est la garantie, pas la lecture qui le precede : deux
 * workers peuvent traiter la meme redelivrance en parallele et franchir ensemble le
 * `hasProcessed()`. Le second `markProcessed()` est alors rejete par l'index, et c'est
 * l'adaptateur qui absorbe ce rejet — le perdant n'a rien a faire de plus.
 *
 * L'index TTL sur `expiresAt` (`expireAfterSeconds: 0`) supprime la ligne a la date qu'elle
 * porte : chaque provenance fixe ainsi sa propre retention dans une collection unique.
 * MongoDB balaie environ toutes les minutes, donc la suppression est differee de ce delai —
 * sans consequence, la date etant deja choisie tres au-dela de toute redelivrance possible.
 *
 * Les index sont poses par `make db-index`, comme tous ceux du mapping.
 */
#[MongoDB\Document(collection: 'processed_event')]
#[MongoDB\UniqueIndex(keys: ['eventId' => 'asc', 'handler' => 'asc'], name: 'processed_event_uniq')]
#[MongoDB\Index(keys: ['expiresAt' => 'asc'], name: 'processed_event_ttl_idx', expireAfterSeconds: 0)]
#[MongoDB\Index(keys: ['source' => 'asc'], name: 'processed_event_source_idx')]
class ProcessedEventDocument
{
    #[MongoDB\Id]
    public ?string $id = null;

    /** Identifiant de l'evenement : frappe par l'emetteur, jamais regenere a la reception. */
    #[MongoDB\Field(type: 'string')]
    public string $eventId;

    /** Nom de classe du handler : le meme evenement peut etre traite par plusieurs reactions. */
    #[MongoDB\Field(type: 'string')]
    public string $handler;

    /** Valeur de `ProcessedEventSource` — lisibilite et purge ciblee, jamais unicite. */
    #[MongoDB\Field(type: 'string')]
    public string $source;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $processedAt;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $expiresAt;
}
