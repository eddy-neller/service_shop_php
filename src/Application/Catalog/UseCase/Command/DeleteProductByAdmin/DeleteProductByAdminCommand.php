<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\DeleteProductByAdmin;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class DeleteProductByAdminCommand implements CommandInterface
{
    public function __construct(
        public string $productId,
    ) {
    }
}
