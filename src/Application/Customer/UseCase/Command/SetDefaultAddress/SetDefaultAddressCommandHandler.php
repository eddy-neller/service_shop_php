<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\SetDefaultAddress;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\ReadModel\AddressItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\Model\Address;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;

/**
 * Le monolithe faisait `unsetDefaultForOwner()` puis `markAsDefault()` : deux ecritures dont
 * PostgreSQL garantissait l'ordre et l'atomicite, la seconde protegee par un index unique
 * partiel. Ni l'un ni l'autre n'est reproductible ici — l'ODM n'ordonne pas les operations
 * d'un flush par intention, et un index partiel echouerait par intermittence selon que le
 * `set` precede ou suive le `unset`.
 *
 * L'agregat fait les deux en memoire, et il n'en sort qu'une seule ecriture de document.
 */
final readonly class SetDefaultAddressCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(SetDefaultAddressCommand $command): AddressItem
    {
        $addressId = AddressId::fromString($command->addressId);
        $ownerId = CustomerId::fromString($command->ownerId);

        $address = $this->transactional->transactional(function () use ($ownerId, $addressId): Address {
            $customer = $this->repository->findById($ownerId);
            $address = $customer?->findAddress($addressId);

            if (null === $customer || null === $address) {
                throw new AddressNotFoundException();
            }

            $customer->setDefaultAddress($addressId, $this->clock->now());

            $this->repository->save($customer);
            $this->eventBus->publishAll($customer->releaseEvents());

            return $address;
        });

        return AddressItem::fromAddress($address);
    }
}
