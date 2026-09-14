<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ordering\Unit\ValueObject;

use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\SharedKernel\Exception\InvalidUuidException;
use PHPUnit\Framework\TestCase;

final class CartLineIdTest extends TestCase
{
    private const string UUID = '550e8400-e29b-41d4-a716-446655440000';

    public function testFromStringCreatesValidCartLineId(): void
    {
        $id = CartLineId::fromString(self::UUID);

        $this->assertSame(self::UUID, $id->toString());
    }

    public function testFromStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid CartLineId: ');

        CartLineId::fromString('');
    }

    public function testFromStringThrowsWhenInvalidUuid(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid CartLineId: not-a-uuid');

        CartLineId::fromString('not-a-uuid');
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $id1 = CartLineId::fromString(self::UUID);
        $id2 = CartLineId::fromString(self::UUID);

        $this->assertTrue($id1->equals($id2));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $id1 = CartLineId::fromString(self::UUID);
        $id2 = CartLineId::fromString('550e8400-e29b-41d4-a716-446655440001');

        $this->assertFalse($id1->equals($id2));
    }

    public function testToStringCastsToString(): void
    {
        $id = CartLineId::fromString(self::UUID);

        $this->assertSame(self::UUID, (string) $id);
    }
}
