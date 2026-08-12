<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\SharedKernel\Exception\ConflictInterface;
use Throwable;

final class CategoryTitleAlreadyUsedException extends CatalogDomainException implements ConflictInterface
{
    public function __construct(
        string $message = 'Category title is already used.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
