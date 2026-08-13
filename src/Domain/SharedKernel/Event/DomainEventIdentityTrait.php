<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Event;

/**
 * Fournit l'identité d'un Domain Event.
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
