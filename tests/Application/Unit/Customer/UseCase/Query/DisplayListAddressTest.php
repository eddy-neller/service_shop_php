<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Query;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Query\DisplayListAddress\DisplayListAddressQuery;
use App\Application\Customer\UseCase\Query\DisplayListAddress\DisplayListAddressQueryHandler;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ce handler filtre, trie et pagine **en memoire**, sur les cinq adresses au plus que porte
 * le document deja lu. C'est donc lui qui remplace une requete SQL, et il faut verifier qu'il
 * en reproduit le contrat : filtres partiels sur `name` et `city`, exact sur `country`, tri
 * sur les quatre champs exposes, et pagination coherente.
 */
final class DisplayListAddressTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private CustomerRepositoryInterface&MockObject $repository;

    private DisplayListAddressQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->handler = new DisplayListAddressQueryHandler($this->repository);
    }

    public function testHandleReturnsEveryAddressOfTheOwner(): void
    {
        $this->givenCustomer($this->aCustomerWith(
            ['Domicile', 'Paris', 'France'],
            ['Bureau', 'Lyon', 'Belgique'],
        ));

        $list = $this->handler->handle($this->query());

        self::assertCount(2, $list->items);
        self::assertSame(2, $list->totalItems);
        self::assertSame(1, $list->totalPages);
    }

    public function testHandleFiltersOnLabelPartiallyAndCaseInsensitively(): void
    {
        $this->givenCustomer($this->aCustomerWith(
            ['Domicile', 'Paris', 'France'],
            ['Bureau principal', 'Lyon', 'France'],
        ));

        $list = $this->handler->handle($this->query(filters: ['name' => 'BUREAU']));

        self::assertCount(1, $list->items);
        self::assertSame('Bureau principal', $list->items[0]->name);
        self::assertSame(1, $list->totalItems);
    }

    public function testHandleFiltersOnCountryExactly(): void
    {
        $this->givenCustomer($this->aCustomerWith(
            ['Domicile', 'Paris', 'France'],
            ['Bureau', 'Bruxelles', 'Belgique'],
        ));

        $list = $this->handler->handle($this->query(filters: ['country' => 'Belgique']));

        self::assertCount(1, $list->items);
        self::assertSame('Bruxelles', $list->items[0]->city);
    }

    /**
     * Un filtre `country` partiel ne doit rien rendre : c'est un exact, contrairement aux deux
     * autres. Confondre les deux elargirait silencieusement les resultats.
     */
    public function testHandleDoesNotMatchAPartialCountry(): void
    {
        $this->givenCustomer($this->aCustomerWith(['Domicile', 'Paris', 'France']));

        $list = $this->handler->handle($this->query(filters: ['country' => 'Fran']));

        self::assertSame([], $list->items);
        self::assertSame(0, $list->totalItems);
    }

    public function testHandleSortsOnTheRequestedField(): void
    {
        $this->givenCustomer($this->aCustomerWith(
            ['Zebre', 'Paris', 'France'],
            ['Alpha', 'Lyon', 'France'],
        ));

        $list = $this->handler->handle($this->query(orderBy: ['name' => 'ASC']));

        self::assertSame('Alpha', $list->items[0]->name);
        self::assertSame('Zebre', $list->items[1]->name);
    }

    public function testHandleSortsDescending(): void
    {
        $this->givenCustomer($this->aCustomerWith(
            ['Alpha', 'Lyon', 'France'],
            ['Zebre', 'Paris', 'France'],
        ));

        $list = $this->handler->handle($this->query(orderBy: ['name' => 'DESC']));

        self::assertSame('Zebre', $list->items[0]->name);
    }

    public function testHandleIgnoresAnUnknownSortField(): void
    {
        $this->givenCustomer($this->aCustomerWith(
            ['Zebre', 'Paris', 'France'],
            ['Alpha', 'Lyon', 'France'],
        ));

        $list = $this->handler->handle($this->query(orderBy: ['zipCode' => 'ASC']));

        self::assertSame('Zebre', $list->items[0]->name);
    }

    public function testHandlePaginates(): void
    {
        $this->givenCustomer($this->aCustomerWith(
            ['Aa', 'Paris', 'France'],
            ['Bb', 'Paris', 'France'],
            ['Cc', 'Paris', 'France'],
        ));

        $list = $this->handler->handle($this->query(page: '2', itemsPerPage: '2', orderBy: ['name' => 'ASC']));

        self::assertCount(1, $list->items);
        self::assertSame('Cc', $list->items[0]->name);
        self::assertSame(3, $list->totalItems);
        self::assertSame(2, $list->totalPages);
    }

    /**
     * Le total porte sur les elements **filtres**, pas sur toutes les adresses : sinon la
     * pagination annoncerait des pages vides.
     */
    public function testHandleCountsTheFilteredSetNotTheWholeOne(): void
    {
        $this->givenCustomer($this->aCustomerWith(
            ['Domicile', 'Paris', 'France'],
            ['Bureau', 'Lyon', 'France'],
            ['Atelier', 'Lille', 'France'],
        ));

        $list = $this->handler->handle($this->query(filters: ['city' => 'Lyon']));

        self::assertSame(1, $list->totalItems);
        self::assertSame(1, $list->totalPages);
    }

    public function testHandleReturnsAnEmptyListForAnUnknownCustomer(): void
    {
        $this->repository->expects($this->once())->method('findById')->willReturn(null);

        $list = $this->handler->handle($this->query());

        self::assertSame([], $list->items);
        self::assertSame(0, $list->totalItems);
    }

    private function givenCustomer(Customer $customer): void
    {
        $this->repository->expects($this->once())->method('findById')->willReturn($customer);
    }

    /**
     * @param array{0: string, 1: string, 2: string} ...$addresses label, ville, pays
     */
    private function aCustomerWith(array ...$addresses): Customer
    {
        $customer = Customer::create(
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
            UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011'),
        );

        foreach ($addresses as $index => [$label, $city, $country]) {
            $customer->addAddress(
                addressId: AddressId::fromString(sprintf('550e8400-e29b-41d4-a716-44665544002%d', $index)),
                label: $label,
                firstname: 'John',
                lastname: 'Doe',
                street: '1 rue de la Paix',
                zipCode: '75000',
                city: $city,
                country: $country,
                phone: '0102030405',
                now: new DateTimeImmutable(sprintf('2025-01-0%d 10:00:00', $index + 1)),
            );
        }

        return $customer;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $orderBy
     */
    private function query(
        ?string $page = null,
        ?string $itemsPerPage = null,
        array $orderBy = [],
        array $filters = [],
    ): DisplayListAddressQuery {
        return new DisplayListAddressQuery(
            ownerId: self::CUSTOMER_ID,
            page: $page,
            itemsPerPage: $itemsPerPage,
            orderBy: $orderBy,
            filters: $filters,
        );
    }
}
