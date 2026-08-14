<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\DeleteAddress;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;

/**
 * La promotion d'une remplacante quand on supprime l'adresse par defaut vit desormais dans
 * `Customer::removeAddress()`. Le monolithe la faisait ici, en deux requetes
 * (`findDefaultReplacementForOwner()` puis `save()`) : deux ecritures que MongoDB n'aurait
 * pas garanties ensemble hors du meme flush.
 */
final readonly class DeleteAddressCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(DeleteAddressCommand $command): void
    {
        $addressId = AddressId::fromString($command->addressId);
        $ownerId = CustomerId::fromString($command->ownerId);

        $this->transactional->transactional(function () use ($ownerId, $addressId): void {
            $customer = $this->repository->findById($ownerId);
            if (null === $customer || null === $customer->findAddress($addressId)) {
                throw new AddressNotFoundException();
            }

            $customer->removeAddress($addressId, $this->clock->now());

            $this->repository->save($customer);
            $this->eventBus->publishAll($customer->releaseEvents());
        });
    }
}
