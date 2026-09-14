<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Command\SetDefaultAddress\SetDefaultAddressCommand;
use App\Application\Customer\UseCase\Command\SetDefaultAddress\SetDefaultAddressCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Event\Address\DefaultAddressChangedEvent;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\Model\Address;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SetDefaultAddressTest extends TestCase
{
    use CustomerBuilderTrait;

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ADDRESS_A = '550e8400-e29b-41d4-a716-446655440020';

    private const string ADDRESS_B = '550e8400-e29b-41d4-a716-446655440021';

    private CustomerRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private SetDefaultAddressCommandHandler $handler;

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

        $this->handler = new SetDefaultAddressCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    /**
     * L'invariant que PostgreSQL tenait par un index unique partiel : apres l'operation il
     * doit rester **exactement** un defaut, l'ancien ayant ete retire dans la meme ecriture.
     */
    public function testHandleMovesTheDefaultAndLeavesExactlyOne(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID, self::ADDRESS_A, self::ADDRESS_B);

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $item = $this->handler->handle(new SetDefaultAddressCommand(self::ADDRESS_B, self::CUSTOMER_ID));

        self::assertTrue($item->isDefault);
        self::assertFalse($customer->findAddress(AddressId::fromString(self::ADDRESS_A))?->isDefault());
        self::assertTrue($customer->findAddress(AddressId::fromString(self::ADDRESS_B))?->isDefault());
        self::assertSame(1, $this->defaultCount($customer));

        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(DefaultAddressChangedEvent::class, $this->publishedEvents[0]);
    }

    public function testHandleOnTheCurrentDefaultPublishesNothing(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID, self::ADDRESS_A, self::ADDRESS_B);

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $item = $this->handler->handle(new SetDefaultAddressCommand(self::ADDRESS_A, self::CUSTOMER_ID));

        self::assertTrue($item->isDefault);
        self::assertSame(1, $this->defaultCount($customer));
        self::assertSame([], $this->publishedEvents);
    }

    public function testHandleRejectsAnUnknownAddress(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID);

        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn($customer);
        $this->repository->expects($this->never())->method('save');
        $this->clock->expects($this->never())->method('now');

        $this->expectException(AddressNotFoundException::class);

        try {
            $this->handler->handle(new SetDefaultAddressCommand(self::ADDRESS_A, self::CUSTOMER_ID));
        } finally {
            self::assertSame([], $this->publishedEvents);
        }
    }

    public function testHandleCannotReachTheAddressOfAnotherCustomer(): void
    {
        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->clock->expects($this->never())->method('now');

        $this->expectException(AddressNotFoundException::class);

        $this->handler->handle(new SetDefaultAddressCommand(self::ADDRESS_A, self::CUSTOMER_ID));
    }

    private function arrange(Customer $customer): void
    {
        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn($customer);
        $this->clock->expects($this->once())->method('now')
            ->willReturn(new DateTimeImmutable('2025-02-01 10:00:00'));
    }

    private function defaultCount(Customer $customer): int
    {
        return count(array_filter(
            $customer->getAddresses(),
            static fn (Address $address): bool => $address->isDefault(),
        ));
    }
}
