<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Customer;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\UserAccountId;
use DateTimeImmutable;

final readonly class CustomerTestDataSeeder
{
    public const string SEED_ADDRESS_LABEL = 'Seed address';

    public function __construct(
        private CustomerRepositoryInterface $customers,
        private TransactionalInterface $transactional,
    ) {
    }

    /** @param array<string, string> $userAccountIds */
    public function seed(array $userAccountIds): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');

        $this->transactional->transactional(function () use ($userAccountIds, $now): void {
            foreach ($userAccountIds as $username => $userAccountId) {
                $customer = Customer::create(
                    id: $this->customers->nextIdentity(),
                    now: $now,
                    userAccountId: UserAccountId::fromString($userAccountId),
                );

                if ('user_member' === $username) {
                    $customer->addAddress(
                        addressId: $this->customers->nextAddressIdentity(),
                        label: self::SEED_ADDRESS_LABEL,
                        firstname: 'John',
                        lastname: 'Doe',
                        street: '1 rue de la Paix',
                        zipCode: '75000',
                        city: 'Paris',
                        country: 'France',
                        phone: '0102030405',
                        now: $now,
                        company: 'Acme',
                    );
                }

                $this->customers->save($customer);
            }
        });
    }
}
