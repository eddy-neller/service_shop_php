<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Query;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Query\DisplayAddress\DisplayAddressQuery;
use App\Application\Customer\UseCase\Query\DisplayAddress\DisplayAddressQueryHandler;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisplayAddressTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ADDRESS_ID = '550e8400-e29b-41d4-a716-446655440020';

    private CustomerRepositoryInterface&MockObject $repository;

    private DisplayAddressQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->handler = new DisplayAddressQueryHandler($this->repository);
    }

    public function testHandleReturnsTheAddressOfItsOwner(): void
    {
        $this->repository->expects($this->once())->method('findById')
            ->willReturn($this->aCustomerWithOneAddress());

        $item = $this->handler->handle(new DisplayAddressQuery(self::ADDRESS_ID, self::CUSTOMER_ID));

        self::assertSame(self::ADDRESS_ID, $item->id);
        self::assertSame(self::CUSTOMER_ID, $item->ownerId);
        self::assertTrue($item->isDefault);
    }

    /**
     * Cherchee dans le client appelant, l'adresse d'autrui est introuvable : 404, pas 403.
     * C'est le remplacement de `ShopAddressVoter`, et il supprime l'oracle d'existence.
     */
    public function testHandleCannotReachTheAddressOfAnotherCustomer(): void
    {
        $this->repository->expects($this->once())->method('findById')
            ->willReturn(Customer::create(
                CustomerId::fromString(self::CUSTOMER_ID),
                new DateTimeImmutable('2025-01-01 10:00:00'),
                UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011'),
            ));

        $this->expectException(AddressNotFoundException::class);

        $this->handler->handle(new DisplayAddressQuery(self::ADDRESS_ID, self::CUSTOMER_ID));
    }

    public function testHandleRejectsAnUnknownCustomer(): void
    {
        $this->repository->expects($this->once())->method('findById')->willReturn(null);

        $this->expectException(AddressNotFoundException::class);

        $this->handler->handle(new DisplayAddressQuery(self::ADDRESS_ID, self::CUSTOMER_ID));
    }

    private function aCustomerWithOneAddress(): Customer
    {
        $customer = Customer::create(
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
            UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011'),
        );

        $customer->addAddress(
            addressId: AddressId::fromString(self::ADDRESS_ID),
            label: 'Domicile',
            firstname: 'John',
            lastname: 'Doe',
            street: '1 rue de la Paix',
            zipCode: '75000',
            city: 'Paris',
            country: 'France',
            phone: '0102030405',
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        return $customer;
    }
}
