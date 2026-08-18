<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Customer;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Shared\Port\TransactionalInterface;
use RuntimeException;

trait CustomerTestDataTrait
{
    protected function seedCustomerTestData(): void
    {
        $container = static::getContainer();
        $customers = $container->get(CustomerRepositoryInterface::class);
        $transactional = $container->get(TransactionalInterface::class);

        if (
            !$customers instanceof CustomerRepositoryInterface
            || !$transactional instanceof TransactionalInterface
        ) {
            throw new RuntimeException('Customer test data services not found.');
        }

        (new CustomerTestDataSeeder($customers, $transactional))->seed([
            $this->userAdmin => $this->userIdOf($this->userAdmin),
            $this->userModer => $this->userIdOf($this->userModer),
            $this->userMember => $this->userIdOf($this->userMember),
            'user_member_1' => $this->userIdOf('user_member_1'),
        ]);
        $this->getManager()->clear();
    }
}
