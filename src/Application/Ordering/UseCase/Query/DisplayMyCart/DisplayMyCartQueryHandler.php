<?php

declare(strict_types=1);

namespace App\Application\Ordering\UseCase\Query\DisplayMyCart;

use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\Service\CartItemFactory;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Domain\Customer\ValueObject\CustomerId;

/**
 * Volontairement **non** cachable, contrairement a `DisplayMyCustomerQuery`.
 *
 * `CartItemFactory` relit les prix du catalogue a chaque affichage : un `CartItem` mis en
 * cache servirait un tarif perime des le premier changement de prix, et l'invalider
 * correctement supposerait qu'un `ProductRepricedEvent` purge **tous** les paniers — un
 * eventail sans tag borne. Ne pas rendre cette query cachable « pour optimiser ».
 */
final readonly class DisplayMyCartQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CartRepositoryInterface $repository,
        private CartItemFactory $factory,
    ) {
    }

    public function handle(DisplayMyCartQuery $query): CartItem
    {
        return $this->factory->create(
            $this->repository->findByOwner(CustomerId::fromString($query->customerId)),
        );
    }
}
