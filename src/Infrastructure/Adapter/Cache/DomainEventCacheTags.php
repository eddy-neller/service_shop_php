<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Cache;

use App\Domain\Catalog\Event\CatalogDomainEventInterface;
use App\Domain\SharedKernel\Event\DomainEventInterface;

/**
 * Source unique des tags de cache a purger pour un fait metier donne.
 *
 * Le `CacheInvalidationMiddleware` orchestre (quand purger), cette classe decide (quoi).
 *
 * Tout fait du catalogue purge les **deux** collections, et ce n'est pas de la paresse :
 * les deux read models se citent l'un l'autre. `ProductItem` embarque le titre de sa
 * categorie, donc renommer une categorie perime la liste des produits ; `CategoryItem`
 * expose `nbProduct`, donc creer un produit perime la liste des categories. Purger une
 * seule des deux laisserait la moitie des lectures fausses.
 *
 * Les queries d'item reutilisent ces tags de collection. Aucun tag d'item specifique n'est donc
 * necessaire : un fait catalogue invalide les listes comme les vues d'item dependantes.
 */
final readonly class DomainEventCacheTags
{
    /**
     * @return list<string> vide si l'evenement n'affecte aucune query cachee
     */
    public function forEvent(DomainEventInterface $event): array
    {
        return match (true) {
            $event instanceof CatalogDomainEventInterface => [
                'categories-collection',
                'products-collection',
            ],
            default => [],
        };
    }
}
