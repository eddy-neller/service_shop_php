<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Exception;

final class InvalidMoneyException extends DomainException implements InvalidArgumentInterface
{
    public static function negativeAmount(): self
    {
        return new self('Money amount cannot be negative.');
    }

    public static function emptyCurrency(): self
    {
        return new self('Currency cannot be empty.');
    }

    public static function currenciesDiffer(): self
    {
        return new self('Money must be in the same currency.');
    }

    public static function negativeMultiplier(): self
    {
        return new self('Money multiplier must be positive.');
    }
}
