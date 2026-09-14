<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayMyCustomer;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Domain\Customer\Exception\CustomerNotFoundException;
use App\Domain\Customer\ValueObject\UserAccountId;

final readonly class DisplayMyCustomerQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
    ) {
    }

    public function handle(DisplayMyCustomerQuery $query): CurrentCustomerItem
    {
        $customer = $this->repository->findByUserAccountId(UserAccountId::fromString($query->userAccountId));
        if (null === $customer) {
            throw new CustomerNotFoundException();
        }

        return CurrentCustomerItem::fromCustomer($customer);
    }
}
