<?php

declare(strict_types=1);

namespace App\Application\Ordering\UseCase\Query\DisplayMyCart;

use App\Application\Shared\CQRS\Query\QueryInterface;

final readonly class DisplayMyCartQuery implements QueryInterface
{
    public function __construct(
        public string $customerId,
    ) {
    }
}
