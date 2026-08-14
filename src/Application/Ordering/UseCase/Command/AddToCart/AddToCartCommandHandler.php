<?php

declare(strict_types=1);

namespace App\Application\Ordering\UseCase\Command\AddToCart;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\Service\CartItemFactory;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\ValueObject\CartLineQuantity;

/**
 * Cree le panier au premier ajout.
 *
 * Le monolithe serialisait cette creation par un verrou pessimiste sur la ligne du client.
 * Ici, deux requetes concurrentes peuvent toutes deux ne rien trouver et construire un
 * panier : c'est l'index unique sur `customerId` qui rejette le perdant, dont la transaction
 * entiere est alors annulee. Le client rejoue et trouve le panier de l'autre.
 */
final readonly class AddToCartCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CartRepositoryInterface $cartRepository,
        private ProductRepositoryInterface $productRepository,
        private CartItemFactory $cartItemFactory,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(AddToCartCommand $command): CartItem
    {
        $productId = ProductId::fromString($command->productId);
        $customerId = CustomerId::fromString($command->customerId);
        $quantity = CartLineQuantity::fromInt($command->quantity);
        $cartId = $this->cartRepository->nextIdentity();
        $cartLineId = $this->cartRepository->nextLineIdentity();

        $cart = $this->transactional->transactional(
            function () use ($productId, $customerId, $cartId, $cartLineId, $quantity): Cart {
                if (null === $this->productRepository->findById($productId)) {
                    throw new ProductNotFoundException();
                }

                $now = $this->clock->now();
                $cart = $this->cartRepository->findByOwner($customerId)
                    ?? Cart::create($cartId, $customerId, $now);

                $cart->addLine($cartLineId, $productId, $quantity, $now);

                $this->cartRepository->save($cart);
                $this->eventBus->publishAll($cart->releaseEvents());

                return $cart;
            },
        );

        return $this->cartItemFactory->create($cart);
    }
}
