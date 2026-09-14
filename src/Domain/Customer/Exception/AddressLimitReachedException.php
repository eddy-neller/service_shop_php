<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exception;

use App\Domain\SharedKernel\Exception\ConflictInterface;
use Throwable;

final class AddressLimitReachedException extends CustomerDomainException implements ConflictInterface
{
    public function __construct(
        string $message = 'A customer cannot have more than 5 addresses.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
