<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Unit\ValueObject;

use App\Domain\Catalog\Exception\InvalidProductTitleException;
use App\Domain\Catalog\ValueObject\ProductTitle;
use PHPUnit\Framework\TestCase;

final class ProductTitleTest extends TestCase
{
    public function testFromStringTrimsWhitespace(): void
    {
        $title = ProductTitle::fromString('  Winter Hat  ');

        $this->assertSame('Winter Hat', $title->toString());
    }

    public function testFromStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidProductTitleException::class);
        $this->expectExceptionMessage('Product title cannot be empty.');

        ProductTitle::fromString('   ');
    }

    public function testFromStringThrowsWhenTooShort(): void
    {
        $this->expectException(InvalidProductTitleException::class);
        $this->expectExceptionMessage('Product title must be at least 2 characters long.');

        ProductTitle::fromString('a');
    }

    public function testFromStringThrowsWhenTooLong(): void
    {
        $this->expectException(InvalidProductTitleException::class);
        $this->expectExceptionMessage('Product title must be at most 100 characters long.');

        ProductTitle::fromString(str_repeat('a', 101));
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $title1 = ProductTitle::fromString('Winter Hat');
        $title2 = ProductTitle::fromString('Winter Hat');

        $this->assertTrue($title1->equals($title2));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $title1 = ProductTitle::fromString('Winter Hat');
        $title2 = ProductTitle::fromString('Summer Hat');

        $this->assertFalse($title1->equals($title2));
    }

    public function testToStringCastsToString(): void
    {
        $title = ProductTitle::fromString('Winter Hat');

        $this->assertSame('Winter Hat', (string) $title);
    }
}
