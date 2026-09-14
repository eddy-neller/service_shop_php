<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Event\Product;

use App\Domain\Catalog\Event\ProductDomainEventInterface;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\SharedKernel\Event\DomainEventIdentityTrait;
use DateTimeImmutable;

final readonly class ProductImageUpdatedEvent implements ProductDomainEventInterface
{
    use DomainEventIdentityTrait;

    public function __construct(
        private ProductId $productId,
        private CategoryId $categoryId,
        private DateTimeImmutable $occurredOn,
    ) {
        $this->eventId = self::generateEventId();
    }

    public function getProductId(): ProductId
    {
        return $this->productId;
    }

    public function getCategoryId(): CategoryId
    {
        return $this->categoryId;
    }

    public function aggregateId(): string
    {
        return $this->productId->toString();
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function eventName(): string
    {
        return 'shop.catalog.product.image_updated';
    }
}
