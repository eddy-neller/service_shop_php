<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayAddress;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\ReadModel\AddressItem;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;

/**
 * L'adresse est cherchee **dans** son proprietaire : celle d'autrui est donc introuvable,
 * pas interdite. Le monolithe repondait 403 via `ShopAddressVoter` ; ici c'est 404, ce qui
 * supprime au passage l'oracle d'existence que le 403 offrait.
 */
final readonly class DisplayAddressQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
    ) {
    }

    public function handle(DisplayAddressQuery $query): AddressItem
    {
        $customer = $this->repository->findById(CustomerId::fromString($query->ownerId));
        $address = $customer?->findAddress(AddressId::fromString($query->addressId));

        if (null === $address) {
            throw new AddressNotFoundException();
        }

        return AddressItem::fromAddress($address);
    }
}
