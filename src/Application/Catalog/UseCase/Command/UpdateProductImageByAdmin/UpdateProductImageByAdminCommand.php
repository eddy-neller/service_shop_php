<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\UpdateProductImageByAdmin;

use App\Application\Shared\CQRS\Command\CommandInterface;
use App\Application\Shared\Port\FileInterface;

final readonly class UpdateProductImageByAdminCommand implements CommandInterface
{
    public function __construct(
        public string $productId,
        public FileInterface $imageFile,
    ) {
    }
}
