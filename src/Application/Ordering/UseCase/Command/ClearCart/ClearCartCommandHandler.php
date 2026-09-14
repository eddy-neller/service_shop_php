<?php

declare(strict_types=1);

namespace App\Application\Ordering\UseCase\Command\ClearCart;

use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\ValueObject\CustomerId;

/**
 * Vider un panier inexistant est un succes silencieux, et vider un panier deja vide ne
 * publie rien (`Cart::clear()` sort tot). `DELETE /me/cart` est donc rejouable a volonte
 * sans deposer de ligne d'outbox pour un fait qui n'a pas eu lieu.
 */
final readonly class ClearCartCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CartRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(ClearCartCommand $command): void
    {
        $customerId = CustomerId::fromString($command->customerId);

        $this->transactional->transactional(function () use ($customerId): void {
            $cart = $this->repository->findByOwner($customerId);
            if (null === $cart) {
                return;
            }

            $cart->clear($this->clock->now());

            $this->repository->save($cart);
            $this->eventBus->publishAll($cart->releaseEvents());
        });
    }
}
