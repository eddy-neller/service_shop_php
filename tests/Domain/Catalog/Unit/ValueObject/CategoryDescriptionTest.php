<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Unit\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategoryDescriptionException;
use App\Domain\Catalog\ValueObject\CategoryDescription;
use PHPUnit\Framework\TestCase;

final class CategoryDescriptionTest extends TestCase
{
    public function testFromStringTrimsWhitespace(): void
    {
        $description = CategoryDescription::fromString('  Nice shoes.  ');

        $this->assertSame('Nice shoes.', $description->toString());
    }

    public function testFromStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidCategoryDescriptionException::class);
        $this->expectExceptionMessage('Category description cannot be empty.');

        CategoryDescription::fromString('   ');
    }

    public function testFromStringThrowsWhenTooShort(): void
    {
        $this->expectException(InvalidCategoryDescriptionException::class);
        $this->expectExceptionMessage('Category description must be at least 2 characters long.');

        CategoryDescription::fromString('a');
    }

    public function testFromStringThrowsWhenTooLong(): void
    {
        $this->expectException(InvalidCategoryDescriptionException::class);
        $this->expectExceptionMessage('Category description must be at most 1000 characters long.');

        CategoryDescription::fromString(str_repeat('a', 1001));
    }

    public function testFromNullableStringReturnsNullWhenNull(): void
    {
        $this->assertNull(CategoryDescription::fromNullableString(null));
    }

    public function testFromNullableStringReturnsNullWhenBlank(): void
    {
        $this->assertNull(CategoryDescription::fromNullableString('   '));
    }

    public function testFromNullableStringReturnsValueObjectWhenNonBlank(): void
    {
        $description = CategoryDescription::fromNullableString('  Nice shoes.  ');

        $this->assertInstanceOf(CategoryDescription::class, $description);
        $this->assertSame('Nice shoes.', $description->toString());
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $description1 = CategoryDescription::fromString('Nice shoes.');
        $description2 = CategoryDescription::fromString('Nice shoes.');

        $this->assertTrue($description1->equals($description2));
    }

    public function testToStringCastsToString(): void
    {
        $description = CategoryDescription::fromString('Nice shoes.');

        $this->assertSame('Nice shoes.', (string) $description);
    }
}
