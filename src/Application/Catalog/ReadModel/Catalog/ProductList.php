<?php

declare(strict_types=1);

namespace App\Application\Catalog\ReadModel\Catalog;

final readonly class ProductList
{
    /**
     * @param list<ProductItem> $items
     */
    public function __construct(
        public array $items,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
