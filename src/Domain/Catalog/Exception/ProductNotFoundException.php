<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\SharedKernel\Exception\EntityNotFoundInterface;
use Throwable;

final class ProductNotFoundException extends CatalogDomainException implements EntityNotFoundInterface
{
    public function __construct(
        string $message = 'Product not found.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
