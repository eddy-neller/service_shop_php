<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\UpdateCategoryByAdmin;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class UpdateCategoryByAdminCommand implements CommandInterface
{
    public function __construct(
        public string $categoryId,
        public ?string $title,
        public ?string $description,
        public ?string $parentId,
    ) {
    }
}
