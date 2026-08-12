<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayProduct;

use App\Application\Shared\CQRS\Query\QueryInterface;

final readonly class DisplayProductQuery implements QueryInterface
{
    public function __construct(
        public string $productId,
    ) {
    }
}
