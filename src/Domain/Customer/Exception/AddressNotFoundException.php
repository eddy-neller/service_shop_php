<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exception;

use App\Domain\SharedKernel\Exception\EntityNotFoundInterface;
use Throwable;

final class AddressNotFoundException extends CustomerDomainException implements EntityNotFoundInterface
{
    public function __construct(
        string $message = 'Address not found.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
