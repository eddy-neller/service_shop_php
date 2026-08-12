<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Double\HttpCache;

use ApiPlatform\HttpCache\PurgerInterface;

/**
 * Purger no-op pour l'environnement de test.
 *
 * Objectif : activer `http_cache.invalidation` en test — donc enregistrer
 * `PurgeHttpCacheListener` sur onFlush — sans en subir les deux effets de bord :
 *
 * - `purge()` : aucune requête HTTP de purge vers Varnish à chaque postFlush.
 * - `getResponseHeaders()` : tableau vide, donc `AddTagsProcessor` n'ajoute aucun
 *   en-tête et les assertions des suites `api.*` restent inchangées.
 *
 * NE PAS retirer ce service en gardant l'invalidation activée : injecté avec
 * `nullOnInvalid()`, un purger absent fait basculer `AddTagsProcessor` sur une
 * branche qui pose `Cache-Tags` en dur sur toute réponse cacheable.
 */
final readonly class NullPurger implements PurgerInterface
{
    /**
     * @param string[] $iris
     */
    public function purge(array $iris): void
    {
    }

    /**
     * @param string[] $iris
     *
     * @return array<string, string>
     */
    public function getResponseHeaders(array $iris): array
    {
        return [];
    }
}
