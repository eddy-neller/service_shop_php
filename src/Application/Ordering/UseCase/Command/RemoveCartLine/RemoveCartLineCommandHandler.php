<?php

declare(strict_types=1);

namespace App\Application\Ordering\UseCase\Command\RemoveCartLine;

use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Exception\CartLineNotFoundException;

final readonly class RemoveCartLineCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CartRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(RemoveCartLineCommand $command): void
    {
        $customerId = CustomerId::fromString($command->customerId);
        $productId = ProductId::fromString($command->productId);

        $this->transactional->transactional(function () use ($customerId, $productId): void {
            $cart = $this->repository->findByOwner($customerId)
                ?? throw new CartLineNotFoundException();

            $cart->removeLine($productId, $this->clock->now());

            $this->repository->save($cart);
            $this->eventBus->publishAll($cart->releaseEvents());
        });
    }
}
