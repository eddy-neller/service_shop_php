<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Unit\ValueObject;

use App\Domain\Catalog\Exception\InvalidProductSubtitleException;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use PHPUnit\Framework\TestCase;

final class ProductSubtitleTest extends TestCase
{
    public function testFromStringTrimsWhitespace(): void
    {
        $subtitle = ProductSubtitle::fromString('  Warm and cozy  ');

        $this->assertSame('Warm and cozy', $subtitle->toString());
    }

    public function testFromStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidProductSubtitleException::class);
        $this->expectExceptionMessage('Product subtitle cannot be empty.');

        ProductSubtitle::fromString('   ');
    }

    public function testFromStringThrowsWhenTooShort(): void
    {
        $this->expectException(InvalidProductSubtitleException::class);
        $this->expectExceptionMessage('Product subtitle must be at least 2 characters long.');

        ProductSubtitle::fromString('a');
    }

    public function testFromStringThrowsWhenTooLong(): void
    {
        $this->expectException(InvalidProductSubtitleException::class);
        $this->expectExceptionMessage('Product subtitle must be at most 150 characters long.');

        ProductSubtitle::fromString(str_repeat('a', 151));
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $subtitle1 = ProductSubtitle::fromString('Warm and cozy');
        $subtitle2 = ProductSubtitle::fromString('Warm and cozy');

        $this->assertTrue($subtitle1->equals($subtitle2));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $subtitle1 = ProductSubtitle::fromString('Warm and cozy');
        $subtitle2 = ProductSubtitle::fromString('Light and breezy');

        $this->assertFalse($subtitle1->equals($subtitle2));
    }

    public function testToStringCastsToString(): void
    {
        $subtitle = ProductSubtitle::fromString('Warm and cozy');

        $this->assertSame('Warm and cozy', (string) $subtitle);
    }
}
