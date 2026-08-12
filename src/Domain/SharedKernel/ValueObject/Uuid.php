<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\ValueObject;

use App\Domain\SharedKernel\Exception\InvalidUuidException;

final class Uuid
{
    // UUID strict RFC 4122 v1–v5 (version + variant contrôlés)
    private const string UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    private function __construct(
        private readonly string $value,
    ) {
    }

    public static function fromString(string $value, string $label = 'Uuid'): self
    {
        if (!preg_match(self::UUID_PATTERN, $value)) {
            throw InvalidUuidException::forValue($label, $value);
        }

        return new self($value);
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
