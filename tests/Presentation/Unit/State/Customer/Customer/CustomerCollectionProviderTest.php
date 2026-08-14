<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Customer\Customer;

use ApiPlatform\Metadata\GetCollection;
use App\Application\Customer\ReadModel\CustomerItem;
use App\Application\Customer\ReadModel\CustomerList;
use App\Application\Customer\UseCase\Query\DisplayListCustomer\DisplayListCustomerQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Presentation\Customer\ApiResource\CustomerResource;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Customer\Presenter\CustomerResourcePresenter;
use App\Presentation\Customer\State\Customer\CustomerCollectionProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomerCollectionProviderTest extends TestCase
{
    public function testItMapsCustomersAndSetsPaginationHeaders(): void
    {
        $request = new Request();
        $queryBus = $this->createMock(QueryBusInterface::class);

        $customer = Customer::create(
            id: CustomerId::fromString('550e8400-e29b-41d4-a716-446655440820'),
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
            userAccountId: UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440821'),
        );
        $output = new CustomerList([CustomerItem::fromCustomer($customer)], 3, 2);

        $queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($output): CustomerList {
                $this->assertInstanceOf(DisplayListCustomerQuery::class, $query);
                $this->assertSame('2', $query->page);
                $this->assertSame('15', $query->itemsPerPage);
                $this->assertSame('1', $query->filters['status'] ?? null);
                $this->assertSame(['createdAt' => 'ASC'], $query->orderBy);

                return $output;
            });

        $provider = new CustomerCollectionProvider(
            $queryBus,
            new CustomerResourcePresenter(new AddressResourcePresenter()),
        );

        $result = $provider->provide(
            new GetCollection(name: 'shop-customers-col'),
            context: [
                'request' => $request,
                'filters' => [
                    'page' => '2',
                    'itemsPerPage' => '15',
                    'status' => '1',
                    'order' => [
                        'createdAt' => 'asc',
                    ],
                ],
            ],
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CustomerResource::class, $result[0]);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440820', $result[0]->id);
        $this->assertSame(3, $request->attributes->get('_total_items'));
        $this->assertSame(2, $request->attributes->get('_total_pages'));
    }

    public function testItHandlesInvalidFiltersWithoutRequest(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);

        $customer = Customer::create(
            id: CustomerId::fromString('550e8400-e29b-41d4-a716-446655440830'),
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
            userAccountId: UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440831'),
        );
        $output = new CustomerList([CustomerItem::fromCustomer($customer)], 1, 1);

        $queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($output): CustomerList {
                $this->assertInstanceOf(DisplayListCustomerQuery::class, $query);
                $this->assertNull($query->page);
                $this->assertNull($query->itemsPerPage);
                $this->assertSame([], $query->filters);
                $this->assertSame([], $query->orderBy);

                return $output;
            });

        $provider = new CustomerCollectionProvider(
            $queryBus,
            new CustomerResourcePresenter(new AddressResourcePresenter()),
        );

        $result = $provider->provide(
            new GetCollection(name: 'shop-customers-col'),
            context: [
                'filters' => 'not-an-array',
            ],
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CustomerResource::class, $result[0]);
    }
}
