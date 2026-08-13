<?php

declare(strict_types=1);

namespace App\Application\Catalog\Port;

use App\Application\Shared\Port\FileInterface;

interface ProductImageValidatorInterface
{
    public function validate(FileInterface $file): void;
}
