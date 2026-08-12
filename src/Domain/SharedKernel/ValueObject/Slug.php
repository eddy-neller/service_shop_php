<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\ValueObject;

use App\Domain\SharedKernel\Exception\InvalidSlugException;

final class Slug
{
    private const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(
        private readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ('' === $normalized) {
            throw InvalidSlugException::empty();
        }

        if (!preg_match(self::SLUG_PATTERN, $normalized)) {
            throw InvalidSlugException::invalidFormat();
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
