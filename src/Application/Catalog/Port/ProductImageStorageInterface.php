<?php

declare(strict_types=1);

namespace App\Application\Catalog\Port;

use App\Application\Shared\Port\FileInterface;

interface ProductImageStorageInterface
{
    public function store(FileInterface $file): string;

    public function remove(string $fileName): void;
}
