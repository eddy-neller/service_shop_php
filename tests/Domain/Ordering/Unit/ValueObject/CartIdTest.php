<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ordering\Unit\ValueObject;

use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\SharedKernel\Exception\InvalidUuidException;
use PHPUnit\Framework\TestCase;

final class CartIdTest extends TestCase
{
    private const string UUID = '550e8400-e29b-41d4-a716-446655440000';

    public function testFromStringCreatesValidCartId(): void
    {
        $id = CartId::fromString(self::UUID);

        $this->assertSame(self::UUID, $id->toString());
    }

    public function testFromStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid CartId: ');

        CartId::fromString('');
    }

    public function testFromStringThrowsWhenInvalidUuid(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid CartId: not-a-uuid');

        CartId::fromString('not-a-uuid');
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $id1 = CartId::fromString(self::UUID);
        $id2 = CartId::fromString(self::UUID);

        $this->assertTrue($id1->equals($id2));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $id1 = CartId::fromString(self::UUID);
        $id2 = CartId::fromString('550e8400-e29b-41d4-a716-446655440001');

        $this->assertFalse($id1->equals($id2));
    }

    public function testToStringCastsToString(): void
    {
        $id = CartId::fromString(self::UUID);

        $this->assertSame(self::UUID, (string) $id);
    }
}
