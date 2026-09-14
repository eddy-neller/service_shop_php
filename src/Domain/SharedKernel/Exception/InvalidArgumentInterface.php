<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Exception;

use Throwable;

/**
 * Marque une violation d'invariant : une valeur fournie est refusée par le domaine.
 * Mappée sur 422 à la frontière (le domaine ignore le code HTTP).
 */
interface InvalidArgumentInterface extends Throwable
{
}
