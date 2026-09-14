<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Exception;

use Throwable;

/**
 * Marque l'absence d'une entité/agrégat recherché.
 * Mappée sur 404 à la frontière (le domaine ignore le code HTTP).
 */
interface EntityNotFoundInterface extends Throwable
{
}
