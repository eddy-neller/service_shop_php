<?php

declare(strict_types=1);

namespace App\Application\Customer\ReadModel;

use App\Domain\Customer\Model\Address;
use DateTimeImmutable;

final readonly class AddressItem
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $name,
        public string $firstname,
        public string $lastname,
        public ?string $company,
        public string $address,
        public string $zip,
        public string $city,
        public string $country,
        public string $phone,
        public bool $isDefault,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromAddress(Address $address): self
    {
        return new self(
            id: $address->getId()->toString(),
            ownerId: $address->getOwnerId()->toString(),
            name: $address->getLabel(),
            firstname: $address->getFirstname(),
            lastname: $address->getLastname(),
            company: $address->getCompany(),
            address: $address->getStreet(),
            zip: $address->getZipCode(),
            city: $address->getCity(),
            country: $address->getCountry(),
            phone: $address->getPhone(),
            isDefault: $address->isDefault(),
            createdAt: $address->getCreatedAt(),
            updatedAt: $address->getUpdatedAt(),
        );
    }
}
