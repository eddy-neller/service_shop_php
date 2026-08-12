<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Event;

/**
 * Fournit l'identité d'un Domain Event.
 *
 * `random_bytes` appartient au cœur de PHP : aucune dépendance framework n'entre
 * dans le Domain. L'événement assigne `$this->eventId = self::generateEventId();`
 * dans son constructeur — l'identifiant est donc fixé une seule fois et voyage avec
 * l'événement lors de la sérialisation, restant identique à chaque redélivrance.
 */
trait DomainEventIdentityTrait
{
    private readonly string $eventId;

    public function eventId(): string
    {
        return $this->eventId;
    }

    private static function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
