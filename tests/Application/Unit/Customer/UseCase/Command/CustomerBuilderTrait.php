<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use DateTimeImmutable;

/**
 * Construit des clients pour les tests des quatre cas d'usage d'adresse.
 *
 * Les adresses etant desormais internes a l'agregat, ces tests ne peuvent plus mocker un
 * depot d'adresses : ils manipulent un vrai `Customer`. Ce trait evite d'en repeter la
 * construction dans quatre fichiers.
 */
trait CustomerBuilderTrait
{
    private function aCustomerWithAddresses(string $customerId, string ...$addressIds): Customer
    {
        $customer = Customer::create(
            CustomerId::fromString($customerId),
            new DateTimeImmutable('2025-01-01 10:00:00'),
            UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011'),
        );

        foreach ($addressIds as $addressId) {
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
                now: new DateTimeImmutable('2025-01-01 10:00:00'),
            );
        }

        $customer->clearDomainEvents();

        return $customer;
    }
}
