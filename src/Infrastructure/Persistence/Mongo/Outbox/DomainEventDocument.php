<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Outbox;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Attribute as MongoDB;

/**
 * Une ligne de l'outbox. Equivalent MongoDB de `messenger_messages`.
 *
 * `body` et `headers` sont le message serialise par le codec Messenger : c'est ce couple
 * que le receiver rend au worker. Les quatre champs qui suivent sont **denormalises** depuis
 * l'evenement — ils ne servent a rien au transport, seulement a rendre la collection lisible
 * dans `mongosh` sans deserialiser un blob PHP.
 *
 * Le document est ecrit via `persist()`, donc dans le flush de l'appelant : c'est toute la
 * raison d'etre de cet outbox. Un `MongoDB\Collection::insertOne()` direct n'aurait pas la
 * session de la transaction et casserait l'atomicite sans lever la moindre erreur.
 */
#[MongoDB\Document(collection: 'domain_event_outbox')]
#[MongoDB\Index(keys: ['queueName' => 'asc', 'availableAt' => 'asc'], name: 'outbox_claim_idx')]
#[MongoDB\Index(keys: ['queueName' => 'asc', 'deliveredAt' => 'asc'], name: 'outbox_delivered_idx')]
class DomainEventDocument
{
    #[MongoDB\Id]
    public ?string $id = null;

    /**
     * File logique. Une seule collection porte l'outbox et sa file d'echec, distinguees
     * par ce champ — c'est le decoupage de `messenger_messages.queue_name`.
     */
    #[MongoDB\Field(type: 'string')]
    public string $queueName;

    #[MongoDB\Field(type: 'string')]
    public string $body;

    /** @var array<string, string> */
    #[MongoDB\Field(type: 'hash')]
    public array $headers = [];

    #[MongoDB\Field(type: 'string')]
    public string $eventName = '';

    #[MongoDB\Field(type: 'string')]
    public string $eventId = '';

    #[MongoDB\Field(type: 'string')]
    public string $aggregateId = '';

    #[MongoDB\Field(type: 'date_immutable', nullable: true)]
    public ?DateTimeImmutable $occurredOn = null;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $createdAt;

    /**
     * Date a partir de laquelle le message est eligible. Un retry replace une copie plus
     * loin dans le temps ; le receiver ne regarde jamais au-dela de `now`.
     */
    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $availableAt;

    /**
     * Date de reservation par un worker. Non nulle = en cours de traitement. Passe ce
     * delai (`redeliver_timeout`), le message est considere abandonne et reprend la file.
     */
    #[MongoDB\Field(type: 'date_immutable', nullable: true)]
    public ?DateTimeImmutable $deliveredAt = null;
}
