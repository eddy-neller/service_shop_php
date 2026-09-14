<?php

declare(strict_types=1);

namespace App\Application\Catalog\Port;

interface ProductImageUrlResolverInterface
{
    public function resolve(?string $imageName): ?string;
}
