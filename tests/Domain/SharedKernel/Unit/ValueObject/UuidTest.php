<?php

declare(strict_types=1);

namespace App\Tests\Domain\SharedKernel\Unit\ValueObject;

use App\Domain\SharedKernel\Exception\InvalidUuidException;
use App\Domain\SharedKernel\ValueObject\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    private const string VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';

    public function testFromStringCreatesUuid(): void
    {
        $uuid = Uuid::fromString(self::VALID_UUID);

        $this->assertSame(self::VALID_UUID, $uuid->toString());
        $this->assertSame(self::VALID_UUID, (string) $uuid);
    }

    public function testFromStringRejectsEmptyValue(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid Uuid: ');

        Uuid::fromString(' ');
    }

    public function testFromStringRejectsInvalidUuid(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid Uuid: not-a-uuid');

        Uuid::fromString('not-a-uuid');
    }

    public function testEqualsComparesByValue(): void
    {
        $uuid = Uuid::fromString(self::VALID_UUID);
        $same = Uuid::fromString(self::VALID_UUID);
        $other = Uuid::fromString('123e4567-e89b-12d3-a456-426614174001');

        $this->assertTrue($uuid->equals($same));
        $this->assertFalse($uuid->equals($other));
    }
}
