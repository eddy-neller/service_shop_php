<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Exception;

final class InvalidUuidException extends DomainException implements InvalidArgumentInterface
{
    public static function forValue(string $label, string $value): self
    {
        return new self(sprintf('Invalid %s: %s', $label, $value));
    }
}
