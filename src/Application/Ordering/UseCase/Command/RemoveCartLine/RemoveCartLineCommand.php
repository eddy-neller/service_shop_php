<?php

declare(strict_types=1);

namespace App\Application\Ordering\UseCase\Command\RemoveCartLine;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class RemoveCartLineCommand implements CommandInterface
{
    public function __construct(
        public string $customerId,
        public string $productId,
    ) {
    }
}
