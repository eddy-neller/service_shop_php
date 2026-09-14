<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Event;

use App\Domain\Catalog\ValueObject\ProductId;

/**
 * Une ligne de panier est identifiee par son **produit**, pas par son propre identifiant :
 * `Cart::addLine()` fusionne deux ajouts du meme produit en une seule ligne, et l'API expose
 * `productId` comme variable d'URI. Les evenements de ligne portent donc le produit, ce qui
 * les rend correlables avec les faits du catalogue.
 *
 * `aggregateId()` reste celui du panier : c'est lui l'agregat ecrit.
 */
interface CartLineDomainEventInterface extends OrderingDomainEventInterface
{
    public function getProductId(): ProductId;
}
