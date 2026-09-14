<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\SharedKernel\Exception\ConflictInterface;

final class CategoryNotEmptyException extends CatalogDomainException implements ConflictInterface
{
    public function __construct()
    {
        parent::__construct('Category must have no products or children before deletion.');
    }
}
