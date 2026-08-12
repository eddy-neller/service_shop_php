<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Unit\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategoryTitleException;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use PHPUnit\Framework\TestCase;

final class CategoryTitleTest extends TestCase
{
    public function testFromStringTrimsWhitespace(): void
    {
        $title = CategoryTitle::fromString('  Shoes  ');

        $this->assertSame('Shoes', $title->toString());
    }

    public function testFromStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidCategoryTitleException::class);
        $this->expectExceptionMessage('Category title cannot be empty.');

        CategoryTitle::fromString('   ');
    }

    public function testFromStringThrowsWhenTooShort(): void
    {
        $this->expectException(InvalidCategoryTitleException::class);
        $this->expectExceptionMessage('Category title must be at least 2 characters long.');

        CategoryTitle::fromString('a');
    }

    public function testFromStringThrowsWhenTooLong(): void
    {
        $this->expectException(InvalidCategoryTitleException::class);
        $this->expectExceptionMessage('Category title must be at most 100 characters long.');

        CategoryTitle::fromString(str_repeat('a', 101));
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $title1 = CategoryTitle::fromString('Shoes');
        $title2 = CategoryTitle::fromString('Shoes');

        $this->assertTrue($title1->equals($title2));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $title1 = CategoryTitle::fromString('Shoes');
        $title2 = CategoryTitle::fromString('Hats');

        $this->assertFalse($title1->equals($title2));
    }

    public function testToStringCastsToString(): void
    {
        $title = CategoryTitle::fromString('Shoes');

        $this->assertSame('Shoes', (string) $title);
    }
}
