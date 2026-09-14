<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\CreateCategoryByAdmin;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class CreateCategoryByAdminCommand implements CommandInterface
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $parentId,
    ) {
    }
}
