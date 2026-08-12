<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Event;

use DateTimeImmutable;

interface DomainEventInterface
{
    /**
     * Identifiant stable, fixé à la création de l'événement et conservé à travers
     * la sérialisation : c'est la clé de déduplication côté consommateur.
     */
    public function eventId(): string;

    /**
     * Identifiant de l'agrégat concerné, sous forme de chaîne.
     *
     * Rend les journaux corrélables sans exposer de donnée personnelle : c'est l'identité
     * technique du sujet de l'événement, pas son e-mail ni son nom.
     */
    public function aggregateId(): string;

    public function occurredOn(): DateTimeImmutable;

    public function eventName(): string;
}
