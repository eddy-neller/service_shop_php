<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Exception;

final class InvalidSlugException extends DomainException implements InvalidArgumentInterface
{
    public static function empty(): self
    {
        return new self('Slug cannot be empty.');
    }

    public static function invalidFormat(): self
    {
        return new self('Slug format is invalid.');
    }
}
