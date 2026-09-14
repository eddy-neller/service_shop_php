<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\UpdateAddress;

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
 * Mise a jour partielle : un champ absent de la commande garde sa valeur actuelle.
 *
 * L'appartenance n'est plus verifiee par un `belongsTo()` apres coup : l'adresse est cherchee
 * **dans** le client, donc une adresse appartenant a quelqu'un d'autre est simplement
 * introuvable. C'est ce qui remplace `ShopAddressVoter`, non porte.
 */
final readonly class UpdateAddressCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(UpdateAddressCommand $command): AddressItem
    {
        $addressId = AddressId::fromString($command->addressId);
        $ownerId = CustomerId::fromString($command->ownerId);

        $address = $this->transactional->transactional(
            function () use ($command, $ownerId, $addressId): Address {
                $customer = $this->repository->findById($ownerId);
                $current = $customer?->findAddress($addressId);

                if (null === $customer || null === $current) {
                    throw new AddressNotFoundException();
                }

                $customer->updateAddress(
                    addressId: $addressId,
                    label: $command->label ?? $current->getLabel(),
                    firstname: $command->firstname ?? $current->getFirstname(),
                    lastname: $command->lastname ?? $current->getLastname(),
                    street: $command->street ?? $current->getStreet(),
                    zipCode: $command->zipCode ?? $current->getZipCode(),
                    city: $command->city ?? $current->getCity(),
                    country: $command->country ?? $current->getCountry(),
                    phone: $command->phone ?? $current->getPhone(),
                    now: $this->clock->now(),
                    company: $command->company ?? $current->getCompany(),
                );

                $this->repository->save($customer);
                $this->eventBus->publishAll($customer->releaseEvents());

                return $current;
            },
        );

        return AddressItem::fromAddress($address);
    }
}
