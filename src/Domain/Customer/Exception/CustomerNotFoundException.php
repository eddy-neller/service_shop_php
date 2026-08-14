<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exception;

use App\Domain\SharedKernel\Exception\EntityNotFoundInterface;
use Throwable;

final class CustomerNotFoundException extends CustomerDomainException implements EntityNotFoundInterface
{
    public function __construct(
        string $message = 'Customer not found.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
