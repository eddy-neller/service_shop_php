<?php

declare(strict_types=1);

namespace App\Tests\Domain\Customer\Unit\ValueObject;

use App\Domain\Customer\Exception\InvalidCustomerStatusException;
use App\Domain\Customer\ValueObject\CustomerStatus;
use PHPUnit\Framework\TestCase;

final class CustomerStatusTest extends TestCase
{
    public function testFromIntRejectsUnsupportedValue(): void
    {
        $this->expectException(InvalidCustomerStatusException::class);
        $this->expectExceptionMessage('Unsupported status: 42.');

        CustomerStatus::fromInt(42);
    }

    public function testActiveStatus(): void
    {
        $status = CustomerStatus::active();

        $this->assertTrue($status->isActive());
        $this->assertFalse($status->isDisabled());
        $this->assertSame(CustomerStatus::ACTIVE, $status->toInt());
    }

    public function testDisabledStatus(): void
    {
        $status = CustomerStatus::disabled();

        $this->assertTrue($status->isDisabled());
        $this->assertFalse($status->isActive());
        $this->assertSame(CustomerStatus::DISABLED, $status->toInt());
    }

    public function testFromIntCreatesStatus(): void
    {
        $status = CustomerStatus::fromInt(CustomerStatus::DISABLED);

        $this->assertTrue($status->isDisabled());
    }
}
