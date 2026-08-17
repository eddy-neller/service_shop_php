<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Event;

use App\Domain\SharedKernel\Event\DomainEventInterface;

/**
 * Fait metier survenu dans le catalogue, categorie ou produit confondus.
 *
 * N'ajoute aucune methode : elle existe pour qu'un consommateur qui reagit a *tout* le
 * catalogue — l'invalidation de cache — se type sur une chose, au lieu de reenumerer les
 * douze evenements a chaque ajout. Une liste explicite laisserait l'oubli d'un evenement
 * ne se voir qu'a la lecture perimee.
 */
interface CatalogDomainEventInterface extends DomainEventInterface
{
}
