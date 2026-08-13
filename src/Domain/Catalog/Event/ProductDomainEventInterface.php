<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Event;

use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductId;

/**
 * Fait metier survenu sur un produit.
 *
 * Expose aussi la categorie de rattachement : `nbProduct` est denormalise sur la categorie,
 * donc tout fait produit peut perimer une lecture de categorie. Un consommateur n'a pas a
 * deviner ce lien.
 *
 * N'etend pas `CategoryDomainEventInterface` : un produit n'est pas une categorie, et un
 * consommateur qui s'abonne aux evenements de categorie ne doit pas recevoir les siens.
 */
interface ProductDomainEventInterface extends CatalogDomainEventInterface
{
    public function getProductId(): ProductId;

    public function getCategoryId(): CategoryId;
}
