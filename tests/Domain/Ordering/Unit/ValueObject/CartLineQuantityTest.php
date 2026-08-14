<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ordering\Unit\ValueObject;

use App\Domain\Ordering\Exception\CartQuantityExceededException;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use PHPUnit\Framework\TestCase;

final class CartLineQuantityTest extends TestCase
{
    public function testFromIntCreatesQuantity(): void
    {
        $quantity = CartLineQuantity::fromInt(3);

        $this->assertSame(3, $quantity->toInt());
    }

    public function testFromIntThrowsWhenBelowMinimum(): void
    {
        $this->expectException(CartQuantityExceededException::class);
        $this->expectExceptionMessage('Cart line quantity must be between 1 and 99.');

        CartLineQuantity::fromInt(0);
    }

    public function testFromIntThrowsWhenAboveMaximum(): void
    {
        $this->expectException(CartQuantityExceededException::class);
        $this->expectExceptionMessage('Cart line quantity must be between 1 and 99.');

        CartLineQuantity::fromInt(100);
    }

    public function testAddReturnsNewQuantity(): void
    {
        $quantity = CartLineQuantity::fromInt(2);

        $newQuantity = $quantity->add(CartLineQuantity::fromInt(3));

        $this->assertSame(2, $quantity->toInt());
        $this->assertSame(5, $newQuantity->toInt());
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $this->assertTrue(CartLineQuantity::fromInt(2)->equals(CartLineQuantity::fromInt(2)));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $this->assertFalse(CartLineQuantity::fromInt(2)->equals(CartLineQuantity::fromInt(3)));
    }

    public function testToStringCastsToString(): void
    {
        $this->assertSame('2', (string) CartLineQuantity::fromInt(2));
    }
}
