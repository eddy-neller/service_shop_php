<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\SharedKernel\Exception\InvalidArgumentInterface;

final class InvalidProductImageException extends CatalogDomainException implements InvalidArgumentInterface
{
    public static function missing(): self
    {
        return new self('No product image file provided.');
    }

    public static function invalidMimeType(string $mimeType): self
    {
        return new self(sprintf('Invalid product image file type: %s.', $mimeType));
    }

    public static function tooLarge(int $maxSize): self
    {
        return new self(sprintf('Product image file exceeds the maximum allowed size (%d bytes).', $maxSize));
    }

    public static function invalidDimensions(int $minDimension, int $maxDimension): self
    {
        return new self(sprintf(
            'Product image dimensions must be between %d and %d pixels.',
            $minDimension,
            $maxDimension,
        ));
    }
}
