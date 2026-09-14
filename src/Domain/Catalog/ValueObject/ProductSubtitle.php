<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidProductSubtitleException;

final readonly class ProductSubtitle
{
    private const int MIN_LENGTH = 2;

    private const int MAX_LENGTH = 150;

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);

        if ('' === $normalized) {
            throw InvalidProductSubtitleException::empty();
        }

        $length = self::stringLength($normalized);

        if ($length < self::MIN_LENGTH) {
            throw InvalidProductSubtitleException::tooShort(self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            throw InvalidProductSubtitleException::tooLong(self::MAX_LENGTH);
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

    private static function stringLength(string $value): int
    {
        return mb_strlen($value);
    }
}
