<?php

declare(strict_types=1);

namespace App\Tests\Domain\Customer\Unit\Model;

use App\Domain\Customer\Exception\InvalidAddressException;
use App\Domain\Customer\Model\Address;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    private const string ADDRESS_ID = '550e8400-e29b-41d4-a716-446655440020';

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440021';

    public function testCreateSetsValuesAndTimestamps(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');

        $address = Address::create(
            id: AddressId::fromString(self::ADDRESS_ID),
            ownerId: CustomerId::fromString(self::CUSTOMER_ID),
            label: 'Home',
            firstname: 'John',
            lastname: 'Doe',
            street: '12 Main St',
            zipCode: '12345',
            city: 'Paris',
            country: 'France',
            phone: '+33 1 23 45 67 89',
            now: $now,
            company: 'ACME',
            isDefault: true,
        );

        $this->assertTrue($address->getId()->equals(AddressId::fromString(self::ADDRESS_ID)));
        $this->assertTrue($address->getOwnerId()->equals(CustomerId::fromString(self::CUSTOMER_ID)));
        $this->assertSame('Home', $address->getLabel());
        $this->assertSame('John', $address->getFirstname());
        $this->assertSame('Doe', $address->getLastname());
        $this->assertSame('ACME', $address->getCompany());
        $this->assertSame('12 Main St', $address->getStreet());
        $this->assertSame('12345', $address->getZipCode());
        $this->assertSame('Paris', $address->getCity());
        $this->assertSame('France', $address->getCountry());
        $this->assertSame('+33 1 23 45 67 89', $address->getPhone());
        $this->assertTrue($address->isDefault());
        $this->assertSame($now, $address->getCreatedAt());
        $this->assertSame($now, $address->getUpdatedAt());
    }

    public function testUpdateTouchesUpdatedAt(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01 10:00:00');
        $address = Address::create(
            id: AddressId::fromString(self::ADDRESS_ID),
            ownerId: CustomerId::fromString(self::CUSTOMER_ID),
            label: 'Home',
            firstname: 'John',
            lastname: 'Doe',
            street: '12 Main St',
            zipCode: '12345',
            city: 'Paris',
            country: 'France',
            phone: '+33 1 23 45 67 89',
            now: $createdAt,
        );

        $updatedAt = new DateTimeImmutable('2025-01-02 10:00:00');
        $address->update(
            label: 'Office',
            firstname: 'Jane',
            lastname: 'Doe',
            street: '45 Second St',
            zipCode: '54321',
            city: 'Lyon',
            country: 'France',
            phone: '+33 6 12 34 56 78',
            now: $updatedAt,
        );

        $this->assertSame('Office', $address->getLabel());
        $this->assertSame('Jane', $address->getFirstname());
        $this->assertSame('Doe', $address->getLastname());
        $this->assertNull($address->getCompany());
        $this->assertSame('45 Second St', $address->getStreet());
        $this->assertSame('54321', $address->getZipCode());
        $this->assertSame('Lyon', $address->getCity());
        $this->assertSame('France', $address->getCountry());
        $this->assertSame('+33 6 12 34 56 78', $address->getPhone());
        $this->assertSame($createdAt, $address->getCreatedAt());
        $this->assertSame($updatedAt, $address->getUpdatedAt());
    }

    public function testMarkAsDefaultTouchesUpdatedAt(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01 10:00:00');
        $address = Address::create(
            id: AddressId::fromString(self::ADDRESS_ID),
            ownerId: CustomerId::fromString(self::CUSTOMER_ID),
            label: 'Home',
            firstname: 'John',
            lastname: 'Doe',
            street: '12 Main St',
            zipCode: '12345',
            city: 'Paris',
            country: 'France',
            phone: '+33 1 23 45 67 89',
            now: $createdAt,
        );

        $updatedAt = new DateTimeImmutable('2025-01-02 10:00:00');
        $address->markAsDefault($updatedAt);

        $this->assertTrue($address->isDefault());
        $this->assertSame($updatedAt, $address->getUpdatedAt());
    }

    public function testUnsetDefaultTouchesUpdatedAt(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01 10:00:00');
        $address = Address::create(
            id: AddressId::fromString(self::ADDRESS_ID),
            ownerId: CustomerId::fromString(self::CUSTOMER_ID),
            label: 'Home',
            firstname: 'John',
            lastname: 'Doe',
            street: '12 Main St',
            zipCode: '12345',
            city: 'Paris',
            country: 'France',
            phone: '+33 1 23 45 67 89',
            now: $createdAt,
            isDefault: true,
        );

        $updatedAt = new DateTimeImmutable('2025-01-02 10:00:00');
        $address->unsetDefault($updatedAt);

        $this->assertFalse($address->isDefault());
        $this->assertSame($updatedAt, $address->getUpdatedAt());
    }

    public function testCreateThrowsOnInvalidLabel(): void
    {
        $this->expectException(InvalidAddressException::class);

        Address::create(
            id: AddressId::fromString(self::ADDRESS_ID),
            ownerId: CustomerId::fromString(self::CUSTOMER_ID),
            label: 'A',
            firstname: 'John',
            lastname: 'Doe',
            street: '12 Main St',
            zipCode: '12345',
            city: 'Paris',
            country: 'France',
            phone: '+33 1 23 45 67 89',
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
        );
    }
}
