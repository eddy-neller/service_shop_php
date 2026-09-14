<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Customer;

use App\Domain\Customer\Model\Address as DomainAddress;
use App\Domain\Customer\Model\Customer as DomainCustomer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\CustomerStatus;
use App\Domain\Customer\ValueObject\UserAccountId;

final readonly class CustomerMapper
{
    public function toDomain(CustomerDocument $document): DomainCustomer
    {
        $addresses = [];
        foreach ($document->addresses as $embedded) {
            $addresses[] = $this->addressToDomain($embedded);
        }

        return DomainCustomer::reconstitute(
            id: CustomerId::fromString($document->id),
            status: CustomerStatus::fromInt($document->status),
            createdAt: $document->createdAt,
            updatedAt: $document->updatedAt,
            userAccountId: null === $document->userAccountId
                ? null
                : UserAccountId::fromString($document->userAccountId),
            addresses: $addresses,
        );
    }

    /**
     * Les adresses sont **reconstruites entierement** a chaque ecriture plutot que
     * reconciliees une a une.
     *
     * Un rapprochement par identifiant serait plus econome en apparence, mais il devrait
     * gerer les suppressions, les reordonnancements et la promotion du defaut — trois
     * chemins ou une divergence entre l'agregat et le document passerait inapercue. Un
     * client porte au plus cinq adresses : la reecriture complete coute une bagatelle et
     * rend impossible tout ecart.
     */
    public function toDocument(DomainCustomer $customer, ?CustomerDocument $document = null): CustomerDocument
    {
        if (null === $document) {
            $document = new CustomerDocument();
            $document->id = $customer->getId()->toString();
        }

        $document->userAccountId = $customer->getUserAccountId()?->toString();
        $document->status = $customer->getStatus()->toInt();
        $document->createdAt = $customer->getCreatedAt();
        $document->updatedAt = $customer->getUpdatedAt();

        $document->addresses->clear();
        foreach ($customer->getAddresses() as $address) {
            $document->addresses->add($this->addressToDocument($address));
        }

        return $document;
    }

    private function addressToDomain(AddressEmbeddedDocument $embedded): DomainAddress
    {
        return DomainAddress::reconstitute(
            id: AddressId::fromString($embedded->id),
            ownerId: CustomerId::fromString($embedded->ownerId),
            label: $embedded->label,
            firstname: $embedded->firstname,
            lastname: $embedded->lastname,
            street: $embedded->street,
            zipCode: $embedded->zipCode,
            city: $embedded->city,
            country: $embedded->country,
            phone: $embedded->phone,
            createdAt: $embedded->createdAt,
            updatedAt: $embedded->updatedAt,
            company: $embedded->company,
            isDefault: $embedded->isDefault,
        );
    }

    private function addressToDocument(DomainAddress $address): AddressEmbeddedDocument
    {
        $embedded = new AddressEmbeddedDocument();
        $embedded->id = $address->getId()->toString();
        $embedded->ownerId = $address->getOwnerId()->toString();
        $embedded->label = $address->getLabel();
        $embedded->firstname = $address->getFirstname();
        $embedded->lastname = $address->getLastname();
        $embedded->company = $address->getCompany();
        $embedded->street = $address->getStreet();
        $embedded->zipCode = $address->getZipCode();
        $embedded->city = $address->getCity();
        $embedded->country = $address->getCountry();
        $embedded->phone = $address->getPhone();
        $embedded->isDefault = $address->isDefault();
        $embedded->createdAt = $address->getCreatedAt();
        $embedded->updatedAt = $address->getUpdatedAt();

        return $embedded;
    }
}
