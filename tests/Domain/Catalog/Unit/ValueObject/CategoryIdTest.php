<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Unit\ValueObject;

use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\SharedKernel\Exception\InvalidUuidException;
use PHPUnit\Framework\TestCase;

final class CategoryIdTest extends TestCase
{
    private const string UUID = '123e4567-e89b-12d3-a456-426614174000';

    public function testFromStringCreatesValidCategoryId(): void
    {
        $id = CategoryId::fromString(self::UUID);

        $this->assertSame(self::UUID, $id->toString());
    }

    public function testFromStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid CategoryId: ');

        CategoryId::fromString('');
    }

    public function testFromStringThrowsWhenInvalidUuid(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid CategoryId: not-a-uuid');

        CategoryId::fromString('not-a-uuid');
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $id1 = CategoryId::fromString(self::UUID);
        $id2 = CategoryId::fromString(self::UUID);

        $this->assertTrue($id1->equals($id2));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $id1 = CategoryId::fromString(self::UUID);
        $id2 = CategoryId::fromString('123e4567-e89b-12d3-a456-426614174001');

        $this->assertFalse($id1->equals($id2));
    }

    public function testToStringCastsToString(): void
    {
        $id = CategoryId::fromString(self::UUID);

        $this->assertSame(self::UUID, (string) $id);
    }
}
