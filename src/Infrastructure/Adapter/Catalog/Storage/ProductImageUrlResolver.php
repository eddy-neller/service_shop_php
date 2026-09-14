<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Catalog\Storage;

use App\Application\Catalog\Port\ProductImageUrlResolverInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final readonly class ProductImageUrlResolver implements ProductImageUrlResolverInterface
{
    public function __construct(
        private ParameterBagInterface $parameterBag,
    ) {
    }

    public function resolve(?string $imageName): ?string
    {
        if (null === $imageName || '' === $imageName) {
            return null;
        }

        return rtrim((string) $this->parameterBag->get('app.product_image.upload_url'), '/') . '/' . ltrim($imageName, '/');
    }
}
