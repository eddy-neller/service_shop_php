<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayProduct;

use App\Application\Shared\CQRS\Query\CacheableQueryInterface;

final readonly class DisplayProductQuery implements CacheableQueryInterface
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        public string $productId,
    ) {
    }

    public function cacheKey(): string
    {
        return 'product-item-' . $this->productId;
    }

    public function cacheTtl(): int
    {
        return self::CACHE_TTL_SECONDS;
    }

    public function cacheTags(): array
    {
        // Un produit embarque le resume de sa categorie ; les deux types de fait le periment.
        return ['categories-collection', 'products-collection'];
    }
}
