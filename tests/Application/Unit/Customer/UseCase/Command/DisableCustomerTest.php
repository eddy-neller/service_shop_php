<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Command\DisableCustomer\DisableCustomerCommand;
use App\Application\Customer\UseCase\Command\DisableCustomer\DisableCustomerCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Event\Customer\CustomerDisabledEvent;
use App\Domain\Customer\Exception\CustomerDomainException;
use App\Domain\Customer\Exception\CustomerNotFoundException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisableCustomerTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private CustomerRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private DisableCustomerCommandHandler $handler;

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

        $this->handler = new DisableCustomerCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleDisablesTheCustomerAndPublishesTheEvent(): void
    {
        $customer = $this->anActiveCustomer();

        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn($customer);
        $this->clock->expects($this->once())->method('now')
            ->willReturn(new DateTimeImmutable('2025-02-01 10:00:00'));
        $this->repository->expects($this->once())->method('save')->with($customer);

        $item = $this->handler->handle(new DisableCustomerCommand(self::CUSTOMER_ID));

        self::assertTrue($customer->getStatus()->isDisabled());
        self::assertSame(self::CUSTOMER_ID, $item->id);
        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(CustomerDisabledEvent::class, $this->publishedEvents[0]);
    }

    /**
     * Le relais de provisionnement rejoue jusqu'a six fois : desactiver un client deja
     * desactive doit rester un succes silencieux, sans republier le fait.
     */
    public function testHandleIsIdempotentAndPublishesNothingTheSecondTime(): void
    {
        $customer = $this->anActiveCustomer();
        $customer->disable(new DateTimeImmutable('2025-01-15 10:00:00'));
        $customer->clearDomainEvents();

        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn($customer);
        $this->clock->expects($this->once())->method('now')
            ->willReturn(new DateTimeImmutable('2025-02-01 10:00:00'));
        $this->repository->expects($this->once())->method('save')->with($customer);

        $item = $this->handler->handle(new DisableCustomerCommand(self::CUSTOMER_ID));

        self::assertTrue($customer->getStatus()->isDisabled());
        self::assertSame(self::CUSTOMER_ID, $item->id);
        self::assertSame([], $this->publishedEvents);
    }

    public function testHandleRejectsAnUnknownCustomer(): void
    {
        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->clock->expects($this->never())->method('now');

        $this->expectException(CustomerNotFoundException::class);

        try {
            $this->handler->handle(new DisableCustomerCommand(self::CUSTOMER_ID));
        } finally {
            self::assertSame([], $this->publishedEvents);
        }
    }

    public function testHandleRejectsACustomerWithoutUserAccount(): void
    {
        $orphan = Customer::create(
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn($orphan);
        $this->repository->expects($this->never())->method('save');
        $this->clock->expects($this->never())->method('now');

        $this->expectException(CustomerDomainException::class);
        $this->expectExceptionMessage('Customer has no user account linked.');

        $this->handler->handle(new DisableCustomerCommand(self::CUSTOMER_ID));
    }

    private function anActiveCustomer(): Customer
    {
        $customer = Customer::create(
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
            UserAccountId::fromString(self::ACCOUNT_ID),
        );
        $customer->clearDomainEvents();

        return $customer;
    }
}
