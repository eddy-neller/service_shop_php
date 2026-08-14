<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exception;

use App\Domain\SharedKernel\Exception\EntityNotFoundInterface;

final class CartLineNotFoundException extends CartDomainException implements EntityNotFoundInterface
{
    public function __construct()
    {
        parent::__construct('Cart line not found.');
    }
}
