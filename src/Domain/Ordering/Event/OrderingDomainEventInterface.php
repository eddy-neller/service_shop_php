<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Event;

use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\SharedKernel\Event\DomainEventInterface;

/**
 * Marqueur du contexte Ordering.
 *
 * Il expose le proprietaire autant que le panier, parce que le panier se lit toujours
 * par son proprietaire (`/api/shop/me/cart`) et jamais par son identifiant : c'est donc
 * `getOwnerId()` qui porte le tag de cache utile.
 */
interface OrderingDomainEventInterface extends DomainEventInterface
{
    public function getCartId(): CartId;

    public function getOwnerId(): CustomerId;
}
