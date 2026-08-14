<?php

declare(strict_types=1);

namespace App\Domain\Customer\Event\Address;

use App\Domain\Customer\Event\AddressDomainEventInterface;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Event\DomainEventIdentityTrait;
use DateTimeImmutable;

final readonly class DefaultAddressChangedEvent implements AddressDomainEventInterface
{
    use DomainEventIdentityTrait;

    public function __construct(
        private CustomerId $customerId,
        private ?UserAccountId $userAccountId,
        private AddressId $addressId,
        private DateTimeImmutable $occurredOn,
    ) {
        $this->eventId = self::generateEventId();
    }

    public function getCustomerId(): CustomerId
    {
        return $this->customerId;
    }

    public function getUserAccountId(): ?UserAccountId
    {
        return $this->userAccountId;
    }

    public function getAddressId(): AddressId
    {
        return $this->addressId;
    }

    public function aggregateId(): string
    {
        return $this->customerId->toString();
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function eventName(): string
    {
        return 'shop.customer.address.defaulted';
    }
}
