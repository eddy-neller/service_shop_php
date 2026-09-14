<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\DeleteCategoryByAdmin;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class DeleteCategoryByAdminCommand implements CommandInterface
{
    public function __construct(
        public string $categoryId,
    ) {
    }
}
