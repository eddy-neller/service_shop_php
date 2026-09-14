<?php

declare(strict_types=1);

namespace App\Tests\Domain\Customer\Unit\Model;

use App\Domain\Customer\Event\Address\AddressAddedEvent;
use App\Domain\Customer\Event\Address\AddressRemovedEvent;
use App\Domain\Customer\Event\Address\AddressUpdatedEvent;
use App\Domain\Customer\Event\Address\DefaultAddressChangedEvent;
use App\Domain\Customer\Event\Customer\CustomerActivatedEvent;
use App\Domain\Customer\Event\Customer\CustomerCreatedEvent;
use App\Domain\Customer\Event\Customer\CustomerDisabledEvent;
use App\Domain\Customer\Exception\AddressLimitReachedException;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\Model\Address;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\CustomerStatus;
use App\Domain\Customer\ValueObject\UserAccountId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private const string ADDRESS_A = '550e8400-e29b-41d4-a716-446655440020';

    private const string ADDRESS_B = '550e8400-e29b-41d4-a716-446655440021';

    private const string ADDRESS_C = '550e8400-e29b-41d4-a716-446655440022';

    public function testCreateMakesAnActiveCustomerAndRecordsTheEvent(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);

        self::assertTrue($customer->getId()->equals(CustomerId::fromString(self::CUSTOMER_ID)));
        self::assertTrue($customer->getStatus()->isActive());
        self::assertTrue($customer->getUserAccountId()?->equals(UserAccountId::fromString(self::ACCOUNT_ID)));
        self::assertSame($now, $customer->getCreatedAt());
        self::assertSame($now, $customer->getUpdatedAt());
        self::assertSame([], $customer->getAddresses());

        $events = $customer->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CustomerCreatedEvent::class, $events[0]);
        self::assertSame('shop.customer.customer.created', $events[0]->eventName());
        self::assertSame(self::CUSTOMER_ID, $events[0]->aggregateId());
        self::assertSame($now, $events[0]->occurredOn());
        self::assertTrue($events[0]->getUserAccountId()?->equals(UserAccountId::fromString(self::ACCOUNT_ID)));
    }

    public function testCreateWithoutUserAccountCarriesNoAccountOnTheEvent(): void
    {
        $customer = Customer::create(
            id: CustomerId::fromString(self::CUSTOMER_ID),
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        self::assertNull($customer->getUserAccountId());

        $events = $customer->releaseEvents();
        self::assertInstanceOf(CustomerCreatedEvent::class, $events[0]);
        self::assertNull($events[0]->getUserAccountId());
    }

    public function testDisableSetsStatusTouchesUpdatedAtAndRecordsTheEvent(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($createdAt);
        $customer->clearDomainEvents();

        $disabledAt = new DateTimeImmutable('2025-01-02 10:00:00');
        $customer->disable($disabledAt);

        self::assertTrue($customer->getStatus()->isDisabled());
        self::assertSame($createdAt, $customer->getCreatedAt());
        self::assertSame($disabledAt, $customer->getUpdatedAt());

        $events = $customer->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CustomerDisabledEvent::class, $events[0]);
        self::assertSame('shop.customer.customer.disabled', $events[0]->eventName());
    }

    /**
     * Le relais de provisionnement rejoue jusqu'a six fois. Sans cette garde, chaque
     * tentative republierait un evenement pour un fait deja acquis.
     */
    public function testDisablingTwiceRecordsNothingTheSecondTime(): void
    {
        $customer = $this->aCustomer(new DateTimeImmutable('2025-01-01 10:00:00'));
        $customer->disable(new DateTimeImmutable('2025-01-02 10:00:00'));
        $customer->clearDomainEvents();

        $untouched = $customer->getUpdatedAt();
        $customer->disable(new DateTimeImmutable('2025-01-03 10:00:00'));

        self::assertSame([], $customer->releaseEvents());
        self::assertSame($untouched, $customer->getUpdatedAt());
    }

    public function testActivateSetsStatusTouchesUpdatedAtAndRecordsTheEvent(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($createdAt);
        $customer->disable(new DateTimeImmutable('2025-01-02 10:00:00'));
        $customer->clearDomainEvents();

        $activatedAt = new DateTimeImmutable('2025-01-03 10:00:00');
        $customer->activate($activatedAt);

        self::assertTrue($customer->getStatus()->isActive());
        self::assertSame($createdAt, $customer->getCreatedAt());
        self::assertSame($activatedAt, $customer->getUpdatedAt());

        $events = $customer->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CustomerActivatedEvent::class, $events[0]);
        self::assertSame('shop.customer.customer.activated', $events[0]->eventName());
    }

    public function testActivatingAnAlreadyActiveCustomerRecordsNothing(): void
    {
        $customer = $this->aCustomer(new DateTimeImmutable('2025-01-01 10:00:00'));
        $customer->clearDomainEvents();

        $untouched = $customer->getUpdatedAt();
        $customer->activate(new DateTimeImmutable('2025-01-02 10:00:00'));

        self::assertSame([], $customer->releaseEvents());
        self::assertSame($untouched, $customer->getUpdatedAt());
    }

    public function testReconstituteRestoresStatusAndAddressesWithoutRecordingAnything(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01 10:00:00');
        $updatedAt = new DateTimeImmutable('2025-01-03 10:00:00');

        $customer = Customer::reconstitute(
            id: CustomerId::fromString(self::CUSTOMER_ID),
            status: CustomerStatus::disabled(),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            userAccountId: UserAccountId::fromString(self::ACCOUNT_ID),
            addresses: [$this->anAddress(self::ADDRESS_A, $createdAt, isDefault: true)],
        );

        self::assertTrue($customer->getStatus()->isDisabled());
        self::assertSame($createdAt, $customer->getCreatedAt());
        self::assertSame($updatedAt, $customer->getUpdatedAt());
        self::assertCount(1, $customer->getAddresses());
        self::assertSame([], $customer->releaseEvents());
    }

    public function testTheFirstAddressBecomesTheDefaultAndTheNextOnesDoNot(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);
        $customer->clearDomainEvents();

        $this->addAddress($customer, self::ADDRESS_A, $now);
        $this->addAddress($customer, self::ADDRESS_B, $now);

        self::assertTrue($customer->findAddress(AddressId::fromString(self::ADDRESS_A))?->isDefault());
        self::assertFalse($customer->findAddress(AddressId::fromString(self::ADDRESS_B))?->isDefault());
        self::assertSame(1, $this->defaultCount($customer));

        $events = $customer->releaseEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(AddressAddedEvent::class, $events[0]);
        self::assertSame('shop.customer.address.added', $events[0]->eventName());
        self::assertSame(self::CUSTOMER_ID, $events[0]->aggregateId());
        self::assertSame(self::ADDRESS_A, $events[0]->getAddressId()->toString());
    }

    public function testAddingBeyondTheLimitIsRejected(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);

        for ($i = 0; $i < Customer::MAX_ADDRESSES; ++$i) {
            $this->addAddress($customer, sprintf('550e8400-e29b-41d4-a716-44665544003%d', $i), $now);
        }

        $customer->clearDomainEvents();

        $this->expectException(AddressLimitReachedException::class);
        $this->expectExceptionMessage('A customer cannot have more than 5 addresses.');

        try {
            $this->addAddress($customer, self::ADDRESS_C, $now);
        } finally {
            self::assertCount(Customer::MAX_ADDRESSES, $customer->getAddresses());
            self::assertSame([], $customer->releaseEvents());
        }
    }

    public function testUpdateAddressRewritesTheFieldsAndRecordsTheEvent(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);
        $this->addAddress($customer, self::ADDRESS_A, $now);
        $customer->clearDomainEvents();

        $updatedAt = new DateTimeImmutable('2025-01-02 10:00:00');
        $customer->updateAddress(
            addressId: AddressId::fromString(self::ADDRESS_A),
            label: 'Bureau',
            firstname: 'Jane',
            lastname: 'Roe',
            street: '9 rue Neuve',
            zipCode: '69000',
            city: 'Lyon',
            country: 'France',
            phone: '0102030405',
            now: $updatedAt,
            company: 'Acme',
        );

        $address = $customer->findAddress(AddressId::fromString(self::ADDRESS_A));
        self::assertNotNull($address);
        self::assertSame('Bureau', $address->getLabel());
        self::assertSame('Acme', $address->getCompany());
        self::assertSame($updatedAt, $customer->getUpdatedAt());

        $events = $customer->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AddressUpdatedEvent::class, $events[0]);
    }

    public function testUpdatingAnUnknownAddressIsRejected(): void
    {
        $customer = $this->aCustomer(new DateTimeImmutable('2025-01-01 10:00:00'));

        $this->expectException(AddressNotFoundException::class);

        $customer->updateAddress(
            addressId: AddressId::fromString(self::ADDRESS_A),
            label: 'Bureau',
            firstname: 'Jane',
            lastname: 'Roe',
            street: '9 rue Neuve',
            zipCode: '69000',
            city: 'Lyon',
            country: 'France',
            phone: '0102030405',
            now: new DateTimeImmutable('2025-01-02 10:00:00'),
        );
    }

    public function testRemovingAnUnknownAddressIsRejected(): void
    {
        $customer = $this->aCustomer(new DateTimeImmutable('2025-01-01 10:00:00'));

        $this->expectException(AddressNotFoundException::class);

        $customer->removeAddress(
            AddressId::fromString(self::ADDRESS_A),
            new DateTimeImmutable('2025-01-02 10:00:00'),
        );
    }

    /**
     * Le depart du defaut doit promouvoir la plus ancienne restante. Ici les deux candidates
     * partagent le meme `createdAt` : c'est donc le depart sur l'identifiant qui tranche, et
     * il doit etre deterministe — sinon la promotion depend de l'ordre de stockage.
     */
    public function testRemovingTheDefaultPromotesTheOldestRemaining(): void
    {
        $first = new DateTimeImmutable('2025-01-01 10:00:00');
        $later = new DateTimeImmutable('2025-01-05 10:00:00');

        $customer = $this->aCustomer($first);
        $this->addAddress($customer, self::ADDRESS_A, $first);
        $this->addAddress($customer, self::ADDRESS_C, $later);
        $this->addAddress($customer, self::ADDRESS_B, $later);
        $customer->clearDomainEvents();

        $customer->removeAddress(AddressId::fromString(self::ADDRESS_A), $later);

        self::assertNull($customer->findAddress(AddressId::fromString(self::ADDRESS_A)));
        self::assertTrue($customer->findAddress(AddressId::fromString(self::ADDRESS_B))?->isDefault());
        self::assertFalse($customer->findAddress(AddressId::fromString(self::ADDRESS_C))?->isDefault());
        self::assertSame(1, $this->defaultCount($customer));

        $events = $customer->releaseEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(AddressRemovedEvent::class, $events[0]);
        self::assertInstanceOf(DefaultAddressChangedEvent::class, $events[1]);
        self::assertSame(self::ADDRESS_B, $events[1]->getAddressId()->toString());
    }

    public function testRemovingANonDefaultLeavesTheDefaultAlone(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);
        $this->addAddress($customer, self::ADDRESS_A, $now);
        $this->addAddress($customer, self::ADDRESS_B, $now);
        $customer->clearDomainEvents();

        $customer->removeAddress(AddressId::fromString(self::ADDRESS_B), $now);

        self::assertTrue($customer->findAddress(AddressId::fromString(self::ADDRESS_A))?->isDefault());

        $events = $customer->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AddressRemovedEvent::class, $events[0]);
    }

    public function testRemovingTheOnlyAddressPromotesNothing(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);
        $this->addAddress($customer, self::ADDRESS_A, $now);
        $customer->clearDomainEvents();

        $customer->removeAddress(AddressId::fromString(self::ADDRESS_A), $now);

        self::assertSame([], $customer->getAddresses());

        $events = $customer->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AddressRemovedEvent::class, $events[0]);
    }

    public function testSetDefaultAddressKeepsExactlyOneDefault(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);
        $this->addAddress($customer, self::ADDRESS_A, $now);
        $this->addAddress($customer, self::ADDRESS_B, $now);
        $customer->clearDomainEvents();

        $changedAt = new DateTimeImmutable('2025-01-02 10:00:00');
        $customer->setDefaultAddress(AddressId::fromString(self::ADDRESS_B), $changedAt);

        self::assertFalse($customer->findAddress(AddressId::fromString(self::ADDRESS_A))?->isDefault());
        self::assertTrue($customer->findAddress(AddressId::fromString(self::ADDRESS_B))?->isDefault());
        self::assertSame(1, $this->defaultCount($customer));
        self::assertSame($changedAt, $customer->getUpdatedAt());

        $events = $customer->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(DefaultAddressChangedEvent::class, $events[0]);
        self::assertSame('shop.customer.address.defaulted', $events[0]->eventName());
    }

    public function testSetDefaultAddressOnTheCurrentDefaultRecordsNothing(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);
        $this->addAddress($customer, self::ADDRESS_A, $now);
        $customer->clearDomainEvents();

        $untouched = $customer->getUpdatedAt();
        $customer->setDefaultAddress(AddressId::fromString(self::ADDRESS_A), new DateTimeImmutable('2025-02-01 10:00:00'));

        self::assertSame([], $customer->releaseEvents());
        self::assertSame($untouched, $customer->getUpdatedAt());
    }

    public function testSettingAnUnknownAddressAsDefaultIsRejected(): void
    {
        $customer = $this->aCustomer(new DateTimeImmutable('2025-01-01 10:00:00'));

        $this->expectException(AddressNotFoundException::class);

        $customer->setDefaultAddress(
            AddressId::fromString(self::ADDRESS_A),
            new DateTimeImmutable('2025-01-02 10:00:00'),
        );
    }

    public function testFindAddressReturnsNullWhenUnknown(): void
    {
        $customer = $this->aCustomer(new DateTimeImmutable('2025-01-01 10:00:00'));

        self::assertNull($customer->findAddress(AddressId::fromString(self::ADDRESS_A)));
    }

    public function testAddedAddressesBelongToTheCustomer(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customer = $this->aCustomer($now);
        $this->addAddress($customer, self::ADDRESS_A, $now);

        self::assertTrue(
            $customer->findAddress(AddressId::fromString(self::ADDRESS_A))
                ?->belongsTo(CustomerId::fromString(self::CUSTOMER_ID)),
        );
    }

    private function aCustomer(DateTimeImmutable $now): Customer
    {
        return Customer::create(
            id: CustomerId::fromString(self::CUSTOMER_ID),
            now: $now,
            userAccountId: UserAccountId::fromString(self::ACCOUNT_ID),
        );
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

    private function anAddress(string $addressId, DateTimeImmutable $now, bool $isDefault): Address
    {
        return Address::create(
            id: AddressId::fromString($addressId),
            ownerId: CustomerId::fromString(self::CUSTOMER_ID),
            label: 'Domicile',
            firstname: 'John',
            lastname: 'Doe',
            street: '1 rue de la Paix',
            zipCode: '75000',
            city: 'Paris',
            country: 'France',
            phone: '0102030405',
            now: $now,
            isDefault: $isDefault,
        );
    }

    private function defaultCount(Customer $customer): int
    {
        return count(array_filter(
            $customer->getAddresses(),
            static fn (Address $address): bool => $address->isDefault(),
        ));
    }
}
