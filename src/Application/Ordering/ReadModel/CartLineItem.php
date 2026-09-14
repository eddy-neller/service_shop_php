<?php

declare(strict_types=1);

namespace App\Application\Ordering\ReadModel;

final readonly class CartLineItem
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $productTitle,
        public string $productSlug,
        public ?string $image,
        public float $unitPrice,
        public int $quantity,
        public float $lineTotal,
    ) {
    }
}
