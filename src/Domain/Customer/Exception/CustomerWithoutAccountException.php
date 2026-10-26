<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exception;

use Throwable;

/**
 * Un client qui n'est rattache a aucun compte ne peut pas etre desactive.
 *
 * Hors categorie semantique et sans ligne dans `exception_to_status` : 400 par le fallback
 * `DomainException`, le statut que la regle avait quand elle vivait dans le handler.
 */
final class CustomerWithoutAccountException extends CustomerDomainException
{
    public function __construct(
        string $message = 'Customer has no user account linked.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
