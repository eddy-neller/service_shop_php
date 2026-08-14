<?php

declare(strict_types=1);

namespace App\Application\Ordering\Port;

use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;

/**
 * Le `findByOwnerForUpdate()` du monolithe n'est **pas** porté, et ne doit pas revenir.
 *
 * Il prenait un verrou pessimiste sur la ligne du client pour servir de mutex a la creation
 * du panier, puis verrouillait le panier lui-meme. MongoDB n'ayant pas de `SELECT … FOR
 * UPDATE`, sa version ici ne pourrait etre qu'un `findByOwner()` portant un nom qui ment —
 * et c'est ce nom qui ferait croire les quatre cas d'usage proteges.
 *
 * Ce qui le remplace :
 *
 * - **creation concurrente** : l'index unique `cart_customer_uniq` sur `customerId`. Le
 *   perdant voit sa transaction entiere annulee, l'appelant rejoue et trouve le panier ;
 * - **mutation concurrente** : le verrou optimiste (`#[MongoDB\Version]`) du document, qui
 *   ressort en `ConcurrentModificationException`. Sans lui, la fusion par produit de
 *   `Cart::addLine()` perdrait silencieusement l'un de deux ajouts simultanes.
 */
interface CartRepositoryInterface
{
    public function nextIdentity(): CartId;

    /**
     * Les lignes n'ont pas de depot a elles — elles vivent dans le panier — mais gardent une
     * identite propre en stockage.
     */
    public function nextLineIdentity(): CartLineId;

    public function findByOwner(CustomerId $ownerId): ?Cart;

    public function save(Cart $cart): void;
}
