<?php

declare(strict_types=1);

namespace App\Application\Shared\Port;

use App\Domain\SharedKernel\Event\DomainEventInterface;

/**
 * Publie les Domain Events libérés par un agrégat.
 *
 * L'implémentation écrit dans un outbox porté par la connexion transactionnelle
 * courante : appelée depuis un callback `TransactionalInterface`, la publication
 * est atomique avec la persistance de l'agrégat. Les réactions, elles, sont
 * exécutées plus tard par un worker.
 */
interface DomainEventBusInterface
{
    /**
     * @param DomainEventInterface[] $events
     */
    public function publishAll(array $events): void;
}
