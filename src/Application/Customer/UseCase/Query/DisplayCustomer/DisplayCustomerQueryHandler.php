<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayCustomer;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\ReadModel\AddressItem;
use App\Application\Customer\ReadModel\CustomerItem;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Domain\Customer\Exception\CustomerNotFoundException;
use App\Domain\Customer\Model\Address;
use App\Domain\Customer\ValueObject\CustomerId;

/**
 * Les adresses ne demandent plus de seconde lecture : elles arrivent avec le client, dans le
 * meme document. Le monolithe faisait ici un `listByOwner()` supplementaire, borne a
 * `MAX_ADDRESSES` et trie par date decroissante — ce tri est conserve pour que la vue reste
 * identique.
 */
final readonly class DisplayCustomerQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
    ) {
    }

    public function handle(DisplayCustomerQuery $query): CustomerItem
    {
        $customer = $this->repository->findById(CustomerId::fromString($query->customerId));
        if (null === $customer) {
            throw new CustomerNotFoundException();
        }

        $addresses = $customer->getAddresses();
        usort(
            $addresses,
            static fn (Address $leftAddress, Address $rightAddress): int => $rightAddress->getCreatedAt() <=> $leftAddress->getCreatedAt(),
        );

        return CustomerItem::fromCustomer(
            customer: $customer,
            addresses: array_map(
                static fn (Address $address): AddressItem => AddressItem::fromAddress($address),
                $addresses,
            ),
        );
    }
}
