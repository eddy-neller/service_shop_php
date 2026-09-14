<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exception;

use App\Domain\SharedKernel\Exception\InvalidArgumentInterface;

final class CartQuantityExceededException extends CartDomainException implements InvalidArgumentInterface
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forCartLineQuantity(): self
    {
        return new self('Cart line quantity must be between 1 and 99.');
    }

    public static function forCartLineQuantityChange(): self
    {
        return new self('Cart line quantity change must be between 0 and 99.');
    }
}
