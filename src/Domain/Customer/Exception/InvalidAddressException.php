<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exception;

use App\Domain\SharedKernel\Exception\InvalidArgumentInterface;

final class InvalidAddressException extends CustomerDomainException implements InvalidArgumentInterface
{
    public static function lengthOutOfRange(string $label, int $min, int $max): self
    {
        return new self(sprintf('%s must be between %d and %d characters.', $label, $min, $max));
    }
}
