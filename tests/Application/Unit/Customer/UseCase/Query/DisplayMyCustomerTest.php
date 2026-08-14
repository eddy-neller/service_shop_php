<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Query;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQueryHandler;
use App\Domain\Customer\Exception\CustomerNotFoundException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisplayMyCustomerTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private CustomerRepositoryInterface&MockObject $repository;

    private DisplayMyCustomerQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->handler = new DisplayMyCustomerQueryHandler($this->repository);
    }

    public function testHandleResolvesTheCustomerOfTheAccount(): void
    {
        $this->repository->expects($this->once())->method('findByUserAccountId')
            ->with($this->callback(
                static fn (UserAccountId $id): bool => self::ACCOUNT_ID === $id->toString(),
            ))
            ->willReturn(Customer::create(
                CustomerId::fromString(self::CUSTOMER_ID),
                new DateTimeImmutable('2025-01-01 10:00:00'),
                UserAccountId::fromString(self::ACCOUNT_ID),
            ));

        $item = $this->handler->handle(new DisplayMyCustomerQuery(self::ACCOUNT_ID));

        self::assertSame(self::CUSTOMER_ID, $item->id);
    }

    /**
     * Le client n'est pas encore provisionne : la query leve, et le cache ne retient rien
     * quand le callback leve — la requete suivante retentera en base.
     */
    public function testHandleRejectsAnAccountWithoutCustomer(): void
    {
        $this->repository->expects($this->once())->method('findByUserAccountId')->willReturn(null);

        $this->expectException(CustomerNotFoundException::class);

        $this->handler->handle(new DisplayMyCustomerQuery(self::ACCOUNT_ID));
    }

    public function testTheCacheKeyAndTagAreScopedToTheAccount(): void
    {
        // La query se lit seule : rien ne doit atteindre le depot.
        $this->repository->expects($this->never())->method('findByUserAccountId');

        $query = new DisplayMyCustomerQuery(self::ACCOUNT_ID);

        self::assertSame('customer-of-user-' . self::ACCOUNT_ID, $query->cacheKey());
        self::assertSame(['customer-of-user-' . self::ACCOUNT_ID], $query->cacheTags());
        self::assertSame(86400, $query->cacheTtl());
    }
}
