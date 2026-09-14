<?php

declare(strict_types=1);

namespace App\Domain\Customer\ValueObject;

use App\Domain\SharedKernel\ValueObject\Uuid;

/**
 * Represents the identity of an account in the User bounded context.
 */
final readonly class UserAccountId
{
    private function __construct(
        private Uuid $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value, 'UserAccountId'));
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
