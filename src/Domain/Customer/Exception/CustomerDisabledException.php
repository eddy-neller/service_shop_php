<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exception;

use Throwable;

/**
 * Un client desactive ne peut plus agir sur son panier ni sur ses adresses.
 *
 * Hors categorie semantique, donc mappee explicitement sur 403 dans `exception_to_status` : le
 * client existe (ce n'est pas un 404) et aucun nouvel essai ne leverait le refus (ce n'est pas
 * un conflit d'etat).
 */
final class CustomerDisabledException extends CustomerDomainException
{
    public function __construct(
        string $message = 'Customer is disabled.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
