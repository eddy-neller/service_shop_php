<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Exception;

use Throwable;

/**
 * Marque un conflit avec l'état courant : unicité violée, limite atteinte, ressource déjà existante.
 * Mappée sur 409 à la frontière (le domaine ignore le code HTTP).
 */
interface ConflictInterface extends Throwable
{
}
