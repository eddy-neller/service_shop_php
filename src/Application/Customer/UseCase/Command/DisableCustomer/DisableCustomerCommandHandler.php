<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\DisableCustomer;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\ReadModel\CustomerItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Exception\CustomerDomainException;
use App\Domain\Customer\Exception\CustomerNotFoundException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;

final readonly class DisableCustomerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(DisableCustomerCommand $command): CustomerItem
    {
        $customerId = CustomerId::fromString($command->customerId);

        $customer = $this->transactional->transactional(function () use ($customerId): Customer {
            $customer = $this->repository->findById($customerId);
            if (null === $customer) {
                throw new CustomerNotFoundException();
            }

            if (null === $customer->getUserAccountId()) {
                throw new CustomerDomainException('Customer has no user account linked.');
            }

            $customer->disable($this->clock->now());
            $this->repository->save($customer);

            // `disable()` sort tot si le client l'est deja : la liste est alors vide, et
            // republier n'a pas lieu. C'est ce qui rend l'appel rejouable.
            $this->eventBus->publishAll($customer->releaseEvents());

            return $customer;
        });

        return CustomerItem::fromCustomer($customer);
    }
}
