<?php

declare(strict_types=1);

namespace App\Domain\Customer\ValueObject;

use App\Domain\SharedKernel\ValueObject\Uuid;

final readonly class AddressId
{
    private function __construct(
        private Uuid $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value, 'AddressId'));
    }

    public function equals(self $other): bool
    {
        return $this->value->equals($other->value);
    }

    public function toString(): string
    {
        return $this->value->toString();
    }

    public function __toString(): string
    {
        return $this->value->toString();
    }
}
