<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayListCategory;

use App\Application\Shared\CQRS\Query\CacheableQueryInterface;

final readonly class DisplayListCategoryQuery implements CacheableQueryInterface
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        public ?string $page = null,
        public ?string $itemsPerPage = null,
        public array $filters = [],
        public array $orderBy = [],
    ) {
    }

    public function cacheKey(): string
    {
        $payload = [
            'page' => $this->page,
            'itemsPerPage' => $this->itemsPerPage,
            'filters' => $this->normalizedFilters(),
            'orderBy' => $this->normalizedOrderBy(),
        ];

        $encoded = serialize($payload);

        return 'category-list-' . hash('sha256', $encoded);
    }

    public function cacheTtl(): int
    {
        return self::CACHE_TTL_SECONDS;
    }

    public function cacheTags(): array
    {
        return ['categories-collection'];
    }

    private function normalizedOrderBy(): array
    {
        $orderBy = $this->orderBy;
        ksort($orderBy);

        return $orderBy;
    }

    private function normalizedFilters(): array
    {
        $filters = $this->filters;
        ksort($filters);

        return $filters;
    }
}
