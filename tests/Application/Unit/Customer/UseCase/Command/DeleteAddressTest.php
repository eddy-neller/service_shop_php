<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Command\DeleteAddress\DeleteAddressCommand;
use App\Application\Customer\UseCase\Command\DeleteAddress\DeleteAddressCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Event\Address\AddressRemovedEvent;
use App\Domain\Customer\Event\Address\DefaultAddressChangedEvent;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeleteAddressTest extends TestCase
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

    private DeleteAddressCommandHandler $handler;

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

        $this->handler = new DeleteAddressCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    /**
     * Supprimer l'adresse par defaut doit en promouvoir une autre : un client ne doit jamais
     * se retrouver avec des adresses dont aucune n'est selectionnee.
     */
    public function testHandleRemovesTheDefaultAndPromotesTheRemainingOne(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID, self::ADDRESS_A, self::ADDRESS_B);

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $this->handler->handle(new DeleteAddressCommand(self::ADDRESS_A, self::CUSTOMER_ID));

        self::assertNull($customer->findAddress(AddressId::fromString(self::ADDRESS_A)));
        self::assertTrue($customer->findAddress(AddressId::fromString(self::ADDRESS_B))?->isDefault());

        self::assertCount(2, $this->publishedEvents);
        self::assertInstanceOf(AddressRemovedEvent::class, $this->publishedEvents[0]);
        self::assertInstanceOf(DefaultAddressChangedEvent::class, $this->publishedEvents[1]);
    }

    public function testHandleRemovingANonDefaultPublishesOnlyTheRemoval(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID, self::ADDRESS_A, self::ADDRESS_B);

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $this->handler->handle(new DeleteAddressCommand(self::ADDRESS_B, self::CUSTOMER_ID));

        self::assertTrue($customer->findAddress(AddressId::fromString(self::ADDRESS_A))?->isDefault());

        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(AddressRemovedEvent::class, $this->publishedEvents[0]);
    }

    public function testHandleRemovingTheLastAddressPromotesNothing(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID, self::ADDRESS_A);

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $this->handler->handle(new DeleteAddressCommand(self::ADDRESS_A, self::CUSTOMER_ID));

        self::assertSame([], $customer->getAddresses());
        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(AddressRemovedEvent::class, $this->publishedEvents[0]);
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
            $this->handler->handle(new DeleteAddressCommand(self::ADDRESS_A, self::CUSTOMER_ID));
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

        $this->handler->handle(new DeleteAddressCommand(self::ADDRESS_A, self::CUSTOMER_ID));
    }

    private function arrange(Customer $customer): void
    {
        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn($customer);
        $this->clock->expects($this->once())->method('now')
            ->willReturn(new DateTimeImmutable('2025-02-01 10:00:00'));
    }
}
