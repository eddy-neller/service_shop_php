<?php

declare(strict_types=1);

namespace App\Domain\Ordering\ValueObject;

use App\Domain\Ordering\Exception\CartQuantityExceededException;

final readonly class CartLineQuantity
{
    private const int MINIMUM = 1;

    private const int MAXIMUM = 99;

    private function __construct(
        private int $value,
    ) {
    }

    public static function fromInt(int $value): self
    {
        if ($value < self::MINIMUM || $value > self::MAXIMUM) {
            throw CartQuantityExceededException::forCartLineQuantity();
        }

        return new self($value);
    }

    public function add(self $other): self
    {
        return self::fromInt($this->value + $other->value);
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
