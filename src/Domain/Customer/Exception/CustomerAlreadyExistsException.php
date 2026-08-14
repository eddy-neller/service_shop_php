<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exception;

use App\Domain\SharedKernel\Exception\ConflictInterface;
use Throwable;

final class CustomerAlreadyExistsException extends CustomerDomainException implements ConflictInterface
{
    public function __construct(
        string $message = 'Customer already exists for this user account.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
