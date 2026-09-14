<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\DataFixtures\dev\Customer;

use App\Infrastructure\Persistence\Mongo\Customer\AddressEmbeddedDocument;
use App\Infrastructure\Persistence\Mongo\Customer\CustomerDocument;
use App\Infrastructure\Symfony\DataFixtures\DataFixturesTrait;
use Doctrine\Bundle\MongoDBBundle\Fixture\Fixture;
use Doctrine\Bundle\MongoDBBundle\Fixture\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;

/**
 * Clients de demonstration avec leurs adresses embarquees.
 *
 * Shop ne connait pas la base Identity : les `userAccountId` sont donc des UUID de
 * fixture et non des relations vers un utilisateur d'un autre service.
 */
final class CustomerFixtures extends Fixture implements FixtureGroupInterface
{
    use DataFixturesTrait;

    private const array CUSTOMERS = [
        'venom' => [
            'reference' => 'customer_venom',
            'addresses' => [
                ['label' => 'Maison', 'firstname' => 'Eddy', 'lastname' => 'Neller', 'company' => 'EN Develop', 'street' => '10 rue de la Paix', 'zipCode' => '75001', 'city' => 'Paris', 'country' => 'France', 'phone' => '+33 1 23 45 67 89'],
                ['label' => 'Bureau', 'firstname' => 'Eddy', 'lastname' => 'Neller', 'company' => 'EN Develop', 'street' => '42 avenue des Champs-Élysées', 'zipCode' => '75008', 'city' => 'Paris', 'country' => 'France', 'phone' => '+33 1 98 76 54 32'],
                ['label' => 'Parents', 'firstname' => 'Eddy', 'lastname' => 'Neller', 'company' => null, 'street' => '3 impasse des Lilas', 'zipCode' => '59000', 'city' => 'Lille', 'country' => 'France', 'phone' => '+33 3 20 11 22 33'],
            ],
        ],
        'marine' => [
            'reference' => 'customer_marine',
            'addresses' => [
                ['label' => 'Bureau', 'firstname' => 'Marine', 'lastname' => 'Durand', 'company' => null, 'street' => '25 avenue Victor Hugo', 'zipCode' => '69001', 'city' => 'Lyon', 'country' => 'France', 'phone' => '+33 4 12 34 56 78'],
                ['label' => 'Domicile', 'firstname' => 'Marine', 'lastname' => 'Durand', 'company' => null, 'street' => '8 rue Bellecour', 'zipCode' => '69002', 'city' => 'Lyon', 'country' => 'France', 'phone' => '+33 6 11 22 33 44'],
                ['label' => 'Famille', 'firstname' => 'Marine', 'lastname' => 'Durand', 'company' => null, 'street' => '17 rue du Rhône', 'zipCode' => '01000', 'city' => 'Bourg-en-Bresse', 'country' => 'France', 'phone' => '+33 4 74 55 66 77'],
            ],
        ],
        'anna' => [
            'reference' => 'customer_anna',
            'addresses' => [
                ['label' => 'Appartement', 'firstname' => 'Anna', 'lastname' => 'Martin', 'company' => null, 'street' => '5 boulevard des Alpes', 'zipCode' => '38000', 'city' => 'Grenoble', 'country' => 'France', 'phone' => '+33 6 98 76 54 32'],
                ['label' => 'Travail', 'firstname' => 'Anna', 'lastname' => 'Martin', 'company' => 'Schneider Electric', 'street' => '35 rue Joseph Monier', 'zipCode' => '92500', 'city' => 'Rueil-Malmaison', 'country' => 'France', 'phone' => '+33 1 41 29 70 00'],
                ['label' => 'Résidence secondaire', 'firstname' => 'Anna', 'lastname' => 'Martin', 'company' => null, 'street' => '12 chemin du Vercors', 'zipCode' => '38250', 'city' => 'Villard-de-Lans', 'country' => 'France', 'phone' => '+33 4 76 95 10 38'],
            ],
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::CUSTOMERS as $username => $data) {
            $customer = $this->createCustomer($username, $data);
            $this->addReference($data['reference'], $customer);
            $manager->persist($customer);
        }

        $manager->flush();
    }

    private function createCustomer(string $username, array $data): CustomerDocument
    {
        $timestamps = $this->generateTimestamps();
        $customer = new CustomerDocument();
        $customer->id = $this->fixtureId('dev:customer', $username);
        $customer->userAccountId = Uuid::uuid5(Uuid::NAMESPACE_OID, 'shop-dev-user:' . $username)->toString();
        $customer->status = 1;
        $customer->createdAt = $timestamps['createdAt'];
        $customer->updatedAt = $timestamps['updatedAt'];

        foreach ($data['addresses'] as $index => $addressData) {
            $customer->addresses->add($this->createAddress($customer->id, $username, $index, $addressData));
        }

        return $customer;
    }

    private function createAddress(string $customerId, string $username, int $index, array $data): AddressEmbeddedDocument
    {
        $timestamps = $this->generateTimestamps();
        $address = new AddressEmbeddedDocument();
        $address->id = $this->fixtureId('dev:address', $username . ':' . $index);
        $address->ownerId = $customerId;
        $address->label = $data['label'];
        $address->firstname = $data['firstname'];
        $address->lastname = $data['lastname'];
        $address->company = $data['company'];
        $address->street = $data['street'];
        $address->zipCode = $data['zipCode'];
        $address->city = $data['city'];
        $address->country = $data['country'];
        $address->phone = $data['phone'];
        $address->isDefault = 0 === $index;
        $address->createdAt = $timestamps['createdAt'];
        $address->updatedAt = $timestamps['updatedAt'];

        return $address;
    }

    public static function getGroups(): array
    {
        return ['dev'];
    }
}
