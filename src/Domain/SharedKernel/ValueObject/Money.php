<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\ValueObject;

use App\Domain\SharedKernel\Exception\InvalidMoneyException;

final readonly class Money
{
    private function __construct(
        private int $amount,
        private string $currency,
    ) {
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return self::fromInt(0, $currency);
    }

    public static function fromInt(int $amount, string $currency = 'EUR'): self
    {
        if ($amount < 0) {
            throw InvalidMoneyException::negativeAmount();
        }

        $normalizedCurrency = strtoupper(trim($currency));

        if ('' === $normalizedCurrency) {
            throw InvalidMoneyException::emptyCurrency();
        }

        return new self($amount, $normalizedCurrency);
    }

    public static function fromEuros(float $euros, string $currency = 'EUR'): self
    {
        return self::fromInt((int) round($euros * 100), $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function multiply(int $multiplier): self
    {
        if ($multiplier < 0) {
            throw InvalidMoneyException::negativeMultiplier();
        }

        return new self($this->amount * $multiplier, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function isZero(): bool
    {
        return 0 === $this->amount;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function toEuros(): float
    {
        return $this->amount / 100;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw InvalidMoneyException::currenciesDiffer();
        }
    }
}
