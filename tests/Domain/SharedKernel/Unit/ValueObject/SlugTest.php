<?php

declare(strict_types=1);

namespace App\Tests\Domain\SharedKernel\Unit\ValueObject;

use App\Domain\SharedKernel\Exception\InvalidSlugException;
use App\Domain\SharedKernel\ValueObject\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testFromStringNormalizesAndCreatesSlug(): void
    {
        $slug = Slug::fromString('  My-Category  ');

        $this->assertSame('my-category', $slug->toString());
        $this->assertSame('my-category', (string) $slug);
    }

    public function testFromStringRejectsEmptyValue(): void
    {
        $this->expectException(InvalidSlugException::class);
        $this->expectExceptionMessage('Slug cannot be empty.');

        Slug::fromString(' ');
    }

    public function testFromStringRejectsInvalidFormat(): void
    {
        $this->expectException(InvalidSlugException::class);
        $this->expectExceptionMessage('Slug format is invalid.');

        Slug::fromString('Invalid slug!');
    }

    public function testEqualsComparesByValue(): void
    {
        $slug = Slug::fromString('my-category');
        $same = Slug::fromString('my-category');
        $other = Slug::fromString('other-category');

        $this->assertTrue($slug->equals($same));
        $this->assertFalse($slug->equals($other));
    }
}
