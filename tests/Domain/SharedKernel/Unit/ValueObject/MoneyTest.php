<?php

declare(strict_types=1);

namespace App\Tests\Domain\SharedKernel\Unit\ValueObject;

use App\Domain\SharedKernel\Exception\InvalidMoneyException;
use App\Domain\SharedKernel\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testFromIntUppercasesAndTrimsCurrency(): void
    {
        $money = Money::fromInt(1000, ' eur ');

        $this->assertSame(1000, $money->amount());
        $this->assertSame('EUR', $money->currency());
    }

    public function testFromIntThrowsWhenNegative(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('Money amount cannot be negative.');

        Money::fromInt(-1);
    }

    public function testFromIntThrowsWhenCurrencyEmpty(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('Currency cannot be empty.');

        Money::fromInt(1000, '   ');
    }

    public function testAddReturnsNewMoney(): void
    {
        $money = Money::fromInt(1000)->add(Money::fromInt(500));

        $this->assertSame(1500, $money->amount());
        $this->assertSame('EUR', $money->currency());
    }

    public function testAddThrowsWhenCurrencyDiffers(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('Money must be in the same currency.');

        Money::fromInt(1000)->add(Money::fromInt(500, 'USD'));
    }

    public function testMultiplyThrowsWhenNegative(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessage('Money multiplier must be positive.');

        Money::fromInt(1000)->multiply(-1);
    }

    public function testMultiplyReturnsNewMoney(): void
    {
        $money = Money::fromInt(1000)->multiply(3);

        $this->assertSame(3000, $money->amount());
        $this->assertSame('EUR', $money->currency());
    }

    public function testIsZero(): void
    {
        $this->assertTrue(Money::zero()->isZero());
        $this->assertFalse(Money::fromInt(1)->isZero());
    }
}
