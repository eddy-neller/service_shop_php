<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Event\Product;

use App\Domain\Catalog\Event\ProductDomainEventInterface;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\SharedKernel\Event\DomainEventIdentityTrait;
use DateTimeImmutable;

/**
 * Titre et/ou sous-titre modifies. Le re-slug qui suit un changement de titre
 * (`Product::reSlug()`) n'emet pas d'evenement propre : c'est une consequence mecanique
 * de ce fait-ci, pas une decision metier distincte.
 */
final readonly class ProductRenamedEvent implements ProductDomainEventInterface
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
        return 'shop.catalog.product.renamed';
    }
}
