<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exception;

use App\Domain\SharedKernel\Exception\InvalidArgumentInterface;

final class InvalidCustomerStatusException extends CustomerDomainException implements InvalidArgumentInterface
{
    public static function unsupported(int $value): self
    {
        return new self(sprintf('Unsupported status: %d.', $value));
    }
}
