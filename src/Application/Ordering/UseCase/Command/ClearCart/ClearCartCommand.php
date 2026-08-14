<?php

declare(strict_types=1);

namespace App\Application\Ordering\UseCase\Command\ClearCart;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class ClearCartCommand implements CommandInterface
{
    public function __construct(
        public string $customerId,
    ) {
    }
}
