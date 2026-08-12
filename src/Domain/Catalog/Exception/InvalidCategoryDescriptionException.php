<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\SharedKernel\Exception\InvalidArgumentInterface;

final class InvalidCategoryDescriptionException extends CatalogDomainException implements InvalidArgumentInterface
{
    public static function empty(): self
    {
        return new self('Category description cannot be empty.');
    }

    public static function tooShort(int $min): self
    {
        return new self(sprintf('Category description must be at least %d characters long.', $min));
    }

    public static function tooLong(int $max): self
    {
        return new self(sprintf('Category description must be at most %d characters long.', $max));
    }
}
