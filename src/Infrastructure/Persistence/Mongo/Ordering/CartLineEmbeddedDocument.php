<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Ordering;

use Doctrine\ODM\MongoDB\Mapping\Attribute as MongoDB;

/**
 * Ligne de panier imbriquee. Aucun prix, aucun titre : ils sont relus dans le catalogue a
 * chaque affichage par `CartItemFactory`. Les figer ici ferait afficher des tarifs perimes.
 */
#[MongoDB\EmbeddedDocument]
class CartLineEmbeddedDocument
{
    #[MongoDB\Field(type: 'string')]
    public string $id;

    #[MongoDB\Field(type: 'string')]
    public string $productId;

    #[MongoDB\Field(type: 'int')]
    public int $quantity;
}
