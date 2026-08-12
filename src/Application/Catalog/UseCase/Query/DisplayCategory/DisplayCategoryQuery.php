<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayCategory;

use App\Application\Shared\CQRS\Query\QueryInterface;

final readonly class DisplayCategoryQuery implements QueryInterface
{
    public function __construct(
        public string $categoryId,
    ) {
    }
}
