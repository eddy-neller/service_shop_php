<?php

declare(strict_types=1);

namespace App\Domain\Ordering\ValueObject;

use App\Domain\Ordering\Exception\CartQuantityExceededException;

final readonly class CartLineQuantityChange
{
    private const int MINIMUM = 0;

    private const int MAXIMUM = 99;

    private function __construct(
        private int $value,
    ) {
    }

    public static function fromInt(int $value): self
    {
        if ($value < self::MINIMUM || $value > self::MAXIMUM) {
            throw CartQuantityExceededException::forCartLineQuantityChange();
        }

        return new self($value);
    }

    public function isRemoval(): bool
    {
        return 0 === $this->value;
    }

    public function toCartLineQuantity(): CartLineQuantity
    {
        return CartLineQuantity::fromInt($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
