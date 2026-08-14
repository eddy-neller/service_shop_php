<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ordering\Unit\Model;

use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Ordering\Exception\CartQuantityExceededException;
use App\Domain\Ordering\Model\CartLine;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartLineTest extends TestCase
{
    public function testQuantityCanBeChangedWithinBounds(): void
    {
        $line = $this->createLine(1);
        $line->setQuantity(CartLineQuantity::fromInt(99));

        self::assertSame(99, $line->getQuantity()->toInt());
    }

    #[DataProvider('invalidQuantities')]
    public function testInvalidQuantityThrows(int $quantity): void
    {
        $this->expectException(CartQuantityExceededException::class);
        $this->createLine($quantity);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'over maximum' => [100];
    }

    private function createLine(int $quantity): CartLine
    {
        return CartLine::create(
            CartLineId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            ProductId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            CartLineQuantity::fromInt($quantity),
        );
    }
}
