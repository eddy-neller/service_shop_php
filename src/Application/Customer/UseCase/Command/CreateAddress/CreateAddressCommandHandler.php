<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\CreateAddress;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\ReadModel\AddressItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\Model\Address;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;

/**
 * Le plafond de cinq adresses et le choix du defaut ne sont plus decides ici : ils sont
 * portes par `Customer::addAddress()`.
 *
 * Ce n'est pas un deplacement cosmetique. Verifier le plafond par un
 * `countByOwnerForUpdate()` qui prenait un verrou pessimiste sur la ligne du client, ce que
 * MongoDB ne sait pas faire. Verifier puis ecrire depuis ce handler laisserait deux requetes
 * concurrentes creer une sixieme adresse sans qu'aucune erreur ne le signale. Dans l'agregat,
 * la verification et la mutation portent sur le meme objet en memoire et partent dans une
 * seule ecriture de document.
 */
final readonly class CreateAddressCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(CreateAddressCommand $command): AddressItem
    {
        $ownerId = CustomerId::fromString($command->ownerId);
        $addressId = $this->repository->nextAddressIdentity();

        return $this->transactional->transactional(
            function () use ($command, $ownerId, $addressId): AddressItem {
                $customer = $this->repository->findById($ownerId);
                if (null === $customer) {
                    throw new AddressNotFoundException();
                }

                $customer->addAddress(
                    addressId: $addressId,
                    label: $command->label,
                    firstname: $command->firstname,
                    lastname: $command->lastname,
                    street: $command->street,
                    zipCode: $command->zipCode,
                    city: $command->city,
                    country: $command->country,
                    phone: $command->phone,
                    now: $this->clock->now(),
                    company: $command->company,
                );

                $this->repository->save($customer);
                $this->eventBus->publishAll($customer->releaseEvents());

                return AddressItem::fromAddress($this->addressOf($customer, $addressId));
            },
        );
    }

    private function addressOf(Customer $customer, AddressId $addressId): Address
    {
        return $customer->findAddress($addressId) ?? throw new AddressNotFoundException();
    }
}
