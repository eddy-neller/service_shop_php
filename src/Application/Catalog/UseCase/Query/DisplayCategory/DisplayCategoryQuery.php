<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayCategory;

use App\Application\Shared\CQRS\Query\CacheableQueryInterface;

final readonly class DisplayCategoryQuery implements CacheableQueryInterface
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        public string $categoryId,
    ) {
    }

    public function cacheKey(): string
    {
        return 'category-item-' . $this->categoryId;
    }

    public function cacheTtl(): int
    {
        return self::CACHE_TTL_SECONDS;
    }

    public function cacheTags(): array
    {
        // La vue contient le parent et les enfants, tandis que `nbProduct` est denormalise.
        // Tout fait catalogue peut donc la perimer.
        return ['categories-collection', 'products-collection'];
    }
}
