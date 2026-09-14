<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Command\CreateCustomer\CreateCustomerCommand;
use App\Application\Customer\UseCase\Command\CreateCustomer\CreateCustomerCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Event\Customer\CustomerCreatedEvent;
use App\Domain\Customer\Exception\CustomerAlreadyExistsException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CreateCustomerTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private CustomerRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private CreateCustomerCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);
        $this->publishedEvents = [];

        $eventBus = $this->createStub(DomainEventBusInterface::class);
        $eventBus->method('publishAll')->willReturnCallback(function (array $events): void {
            $this->publishedEvents = [...$this->publishedEvents, ...$events];
        });

        $this->handler = new CreateCustomerCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleCreatesAnActiveCustomerAndPublishesTheEvent(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);

        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('nextIdentity')->willReturn($customerId);
        $this->repository->expects($this->once())->method('findByUserAccountId')->willReturn(null);
        $this->clock->expects($this->once())->method('now')->willReturn($now);

        $saved = null;
        $this->repository->expects($this->once())->method('save')
            ->willReturnCallback(function (Customer $customer) use (&$saved): void {
                $saved = $customer;
            });

        $item = $this->handler->handle(new CreateCustomerCommand(self::ACCOUNT_ID));

        self::assertSame(self::CUSTOMER_ID, $item->id);
        self::assertSame(self::ACCOUNT_ID, $item->userAccountId);
        self::assertInstanceOf(Customer::class, $saved);
        self::assertTrue($saved->getStatus()->isActive());
        self::assertSame([], $item->addresses);

        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(CustomerCreatedEvent::class, $this->publishedEvents[0]);
        self::assertSame(self::CUSTOMER_ID, $this->publishedEvents[0]->aggregateId());
    }

    public function testHandleRejectsASecondCustomerForTheSameAccount(): void
    {
        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('nextIdentity')
            ->willReturn(CustomerId::fromString(self::CUSTOMER_ID));
        $this->repository->expects($this->once())->method('findByUserAccountId')
            ->with($this->callback(
                static fn (UserAccountId $id): bool => self::ACCOUNT_ID === $id->toString(),
            ))
            ->willReturn(Customer::create(
                CustomerId::fromString(self::CUSTOMER_ID),
                new DateTimeImmutable('2024-01-01 10:00:00'),
                UserAccountId::fromString(self::ACCOUNT_ID),
            ));
        $this->repository->expects($this->never())->method('save');
        $this->clock->expects($this->never())->method('now');

        $this->expectException(CustomerAlreadyExistsException::class);

        try {
            $this->handler->handle(new CreateCustomerCommand(self::ACCOUNT_ID));
        } finally {
            self::assertSame([], $this->publishedEvents);
        }
    }
}
