<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Event\Cart;

use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Event\CartLineDomainEventInterface;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\SharedKernel\Event\DomainEventIdentityTrait;
use DateTimeImmutable;

final readonly class CartLineQuantityChangedEvent implements CartLineDomainEventInterface
{
    use DomainEventIdentityTrait;

    public function __construct(
        private CartId $cartId,
        private CustomerId $ownerId,
        private ProductId $productId,
        private DateTimeImmutable $occurredOn,
    ) {
        $this->eventId = self::generateEventId();
    }

    public function getCartId(): CartId
    {
        return $this->cartId;
    }

    public function getOwnerId(): CustomerId
    {
        return $this->ownerId;
    }

    public function getProductId(): ProductId
    {
        return $this->productId;
    }

    public function aggregateId(): string
    {
        return $this->cartId->toString();
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function eventName(): string
    {
        return 'shop.ordering.cart.line_quantity_changed';
    }
}
