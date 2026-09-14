<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Event;

use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * Fait metier survenu sur une categorie.
 *
 * Rend contractuel l'acces au `CategoryId` pour qu'un consommateur puisse reagir a *tout*
 * evenement de categorie sans les enumerer un par un — c'est ce dont se sert
 * `CatalogCacheTags` pour purger `categories-collection`.
 */
interface CategoryDomainEventInterface extends CatalogDomainEventInterface
{
    public function getCategoryId(): CategoryId;
}
