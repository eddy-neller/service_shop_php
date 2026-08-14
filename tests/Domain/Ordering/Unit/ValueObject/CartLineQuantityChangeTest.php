<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ordering\Unit\ValueObject;

use App\Domain\Ordering\Exception\CartQuantityExceededException;
use App\Domain\Ordering\ValueObject\CartLineQuantityChange;
use PHPUnit\Framework\TestCase;

final class CartLineQuantityChangeTest extends TestCase
{
    public function testFromIntCreatesChange(): void
    {
        $change = CartLineQuantityChange::fromInt(3);

        $this->assertSame(3, $change->toInt());
        $this->assertFalse($change->isRemoval());
        $this->assertSame(3, $change->toCartLineQuantity()->toInt());
    }

    public function testFromIntAcceptsZeroAsRemoval(): void
    {
        $change = CartLineQuantityChange::fromInt(0);

        $this->assertTrue($change->isRemoval());
    }

    public function testFromIntThrowsWhenBelowMinimum(): void
    {
        $this->expectException(CartQuantityExceededException::class);
        $this->expectExceptionMessage('Cart line quantity change must be between 0 and 99.');

        CartLineQuantityChange::fromInt(-1);
    }

    public function testFromIntThrowsWhenAboveMaximum(): void
    {
        $this->expectException(CartQuantityExceededException::class);
        $this->expectExceptionMessage('Cart line quantity change must be between 0 and 99.');

        CartLineQuantityChange::fromInt(100);
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $this->assertTrue(CartLineQuantityChange::fromInt(2)->equals(CartLineQuantityChange::fromInt(2)));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $this->assertFalse(CartLineQuantityChange::fromInt(2)->equals(CartLineQuantityChange::fromInt(3)));
    }

    public function testToStringCastsToString(): void
    {
        $this->assertSame('2', (string) CartLineQuantityChange::fromInt(2));
    }
}
