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

    /**
     * Resout le client **et** verifie qu'il peut agir. Le refus a lieu avant le `return` : rien
     * n'est mis en cache quand le handler leve, donc un client desactive n'est jamais servi par
     * le cache.
     */
    public function handle(DisplayMyCustomerQuery $query): CurrentCustomerItem
    {
        $customer = $this->repository->findByUserAccountId(UserAccountId::fromString($query->userAccountId));
        if (null === $customer) {
            throw new CustomerNotFoundException();
        }

        $customer->assertActive();

        return CurrentCustomerItem::fromCustomer($customer);
    }
}
