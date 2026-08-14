<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Query;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Query\DisplayCustomer\DisplayCustomerQuery;
use App\Application\Customer\UseCase\Query\DisplayCustomer\DisplayCustomerQueryHandler;
use App\Domain\Customer\Exception\CustomerNotFoundException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisplayCustomerTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ADDRESS_A = '550e8400-e29b-41d4-a716-446655440020';

    private const string ADDRESS_B = '550e8400-e29b-41d4-a716-446655440021';

    private CustomerRepositoryInterface&MockObject $repository;

    private DisplayCustomerQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->handler = new DisplayCustomerQueryHandler($this->repository);
    }

    /**
     * Les adresses arrivent avec le client, dans le meme document : le monolithe faisait ici
     * une seconde lecture. Le tri par date decroissante de sa vue est conserve.
     */
    public function testHandleReturnsTheCustomerWithItsAddressesNewestFirst(): void
    {
        $customer = Customer::create(
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
            UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011'),
        );
        $this->addAddress($customer, self::ADDRESS_A, new DateTimeImmutable('2025-01-01 10:00:00'));
        $this->addAddress($customer, self::ADDRESS_B, new DateTimeImmutable('2025-03-01 10:00:00'));

        $this->repository->expects($this->once())->method('findById')->willReturn($customer);

        $item = $this->handler->handle(new DisplayCustomerQuery(self::CUSTOMER_ID));

        self::assertSame(self::CUSTOMER_ID, $item->id);
        self::assertCount(2, $item->addresses);
        self::assertSame(self::ADDRESS_B, $item->addresses[0]->id);
        self::assertSame(self::ADDRESS_A, $item->addresses[1]->id);
    }

    public function testHandleRejectsAnUnknownCustomer(): void
    {
        $this->repository->expects($this->once())->method('findById')->willReturn(null);

        $this->expectException(CustomerNotFoundException::class);

        $this->handler->handle(new DisplayCustomerQuery(self::CUSTOMER_ID));
    }

    private function addAddress(Customer $customer, string $addressId, DateTimeImmutable $now): void
    {
        $customer->addAddress(
            addressId: AddressId::fromString($addressId),
            label: 'Domicile',
            firstname: 'John',
            lastname: 'Doe',
            street: '1 rue de la Paix',
            zipCode: '75000',
            city: 'Paris',
            country: 'France',
            phone: '0102030405',
            now: $now,
        );
    }
}
