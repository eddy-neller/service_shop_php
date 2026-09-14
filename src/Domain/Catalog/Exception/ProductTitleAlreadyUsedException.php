<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\SharedKernel\Exception\ConflictInterface;
use Throwable;

final class ProductTitleAlreadyUsedException extends CatalogDomainException implements ConflictInterface
{
    public function __construct(
        string $message = 'Product title is already used.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
