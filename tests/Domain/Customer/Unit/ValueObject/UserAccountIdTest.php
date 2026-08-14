<?php

declare(strict_types=1);

namespace App\Tests\Domain\Customer\Unit\ValueObject;

use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Exception\InvalidUuidException;
use PHPUnit\Framework\TestCase;

final class UserAccountIdTest extends TestCase
{
    private const string UUID = '123e4567-e89b-12d3-a456-426614174000';

    public function testFromStringCreatesValidUserAccountId(): void
    {
        $id = UserAccountId::fromString(self::UUID);

        $this->assertSame(self::UUID, $id->toString());
    }

    public function testToStringMagicMethodReturnsUuid(): void
    {
        $id = UserAccountId::fromString(self::UUID);

        $this->assertSame(self::UUID, (string) $id);
    }

    public function testFromStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid UserAccountId: ');

        UserAccountId::fromString('');
    }

    public function testFromStringThrowsWhenInvalidUuid(): void
    {
        $this->expectException(InvalidUuidException::class);
        $this->expectExceptionMessage('Invalid UserAccountId: not-a-uuid');

        UserAccountId::fromString('not-a-uuid');
    }
}
