<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Event;

use App\Domain\SharedKernel\Event\DomainEventInterface;

/**
 * Fait metier survenu dans le catalogue, categorie ou produit confondus.
 *
 * N'ajoute aucune methode : elle existe pour qu'un consommateur qui reagit a *tout* le
 * catalogue — l'invalidation de cache — se type sur une chose, au lieu de reenumerer les
 * douze evenements a chaque ajout. C'est la lecon de `UserDomainEventInterface` cote
 * monolithe, ou l'oubli d'un evenement dans la liste ne se voit qu'a la lecture perimee.
 */
interface CatalogDomainEventInterface extends DomainEventInterface
{
}
