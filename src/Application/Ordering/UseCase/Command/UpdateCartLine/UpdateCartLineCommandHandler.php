<?php

declare(strict_types=1);

namespace App\Application\Ordering\UseCase\Command\UpdateCartLine;

use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\Service\CartItemFactory;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Exception\CartLineNotFoundException;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\ValueObject\CartLineQuantityChange;

/**
 * Une quantite a zero vaut retrait : c'est `Cart::changeLineQuantity()` qui tranche, et
 * l'evenement publie dit alors `line_removed`, pas `line_quantity_changed`.
 */
final readonly class UpdateCartLineCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CartRepositoryInterface $repository,
        private CartItemFactory $cartItemFactory,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(UpdateCartLineCommand $command): CartItem
    {
        $customerId = CustomerId::fromString($command->customerId);
        $productId = ProductId::fromString($command->productId);
        $quantity = CartLineQuantityChange::fromInt($command->quantity);

        $cart = $this->transactional->transactional(
            function () use ($customerId, $productId, $quantity): Cart {
                $cart = $this->repository->findByOwner($customerId)
                    ?? throw new CartLineNotFoundException();

                $cart->changeLineQuantity($productId, $quantity, $this->clock->now());

                $this->repository->save($cart);
                $this->eventBus->publishAll($cart->releaseEvents());

                return $cart;
            },
        );

        return $this->cartItemFactory->create($cart);
    }
}
