<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Query;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Query\DisplayListCustomer\DisplayListCustomerQuery;
use App\Application\Customer\UseCase\Query\DisplayListCustomer\DisplayListCustomerQueryHandler;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisplayListCustomerTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private CustomerRepositoryInterface&MockObject $repository;

    private DisplayListCustomerQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->handler = new DisplayListCustomerQueryHandler($this->repository);
    }

    public function testHandleReturnsThePaginatedList(): void
    {
        $this->repository->expects($this->once())->method('list')
            ->with([], ['createdAt' => 'DESC'], 2, 5)
            ->willReturn(['items' => [$this->aCustomer()], 'totalItems' => 7, 'totalPages' => 2]);

        $list = $this->handler->handle(new DisplayListCustomerQuery('2', '5'));

        self::assertCount(1, $list->items);
        self::assertSame(self::CUSTOMER_ID, $list->items[0]->id);
        self::assertSame(7, $list->totalItems);
        self::assertSame(2, $list->totalPages);
    }

    /**
     * Sans `orderBy`, les plus recents sont affiches d'abord.
     */
    public function testHandleFallsBackToNewestFirst(): void
    {
        $this->repository->expects($this->once())->method('list')
            ->with([], ['createdAt' => 'DESC'], 1, 30)
            ->willReturn(['items' => [], 'totalItems' => 0, 'totalPages' => 0]);

        $list = $this->handler->handle(new DisplayListCustomerQuery());

        self::assertSame([], $list->items);
    }

    public function testHandleForwardsTheSuppliedOrdering(): void
    {
        $this->repository->expects($this->once())->method('list')
            ->with(['status' => 1], ['status' => 'ASC'], 1, 30)
            ->willReturn(['items' => [], 'totalItems' => 0, 'totalPages' => 0]);

        $this->handler->handle(new DisplayListCustomerQuery(
            filters: ['status' => 1],
            orderBy: ['status' => 'ASC'],
        ));
    }

    private function aCustomer(): Customer
    {
        return Customer::create(
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
            UserAccountId::fromString('550e8400-e29b-41d4-a716-446655440011'),
        );
    }
}
