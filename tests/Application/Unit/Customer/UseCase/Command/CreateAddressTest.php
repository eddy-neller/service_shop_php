<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Command\CreateAddress\CreateAddressCommand;
use App\Application\Customer\UseCase\Command\CreateAddress\CreateAddressCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Event\Address\AddressAddedEvent;
use App\Domain\Customer\Exception\AddressLimitReachedException;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\AddressId;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CreateAddressTest extends TestCase
{
    use CustomerBuilderTrait;

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ADDRESS_ID = '550e8400-e29b-41d4-a716-446655440020';

    private CustomerRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private CreateAddressCommandHandler $handler;

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

        $this->handler = new CreateAddressCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleAddsTheAddressToTheCustomerAndMarksTheFirstAsDefault(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID);

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $item = $this->handler->handle($this->command());

        self::assertSame(self::ADDRESS_ID, $item->id);
        self::assertSame(self::CUSTOMER_ID, $item->ownerId);
        self::assertTrue($item->isDefault);
        self::assertCount(1, $customer->getAddresses());

        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(AddressAddedEvent::class, $this->publishedEvents[0]);
        self::assertSame(self::CUSTOMER_ID, $this->publishedEvents[0]->aggregateId());
    }

    public function testHandleDoesNotMakeASecondAddressDefault(): void
    {
        $customer = $this->aCustomerWithAddresses(
            self::CUSTOMER_ID,
            '550e8400-e29b-41d4-a716-446655440021',
        );

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $item = $this->handler->handle($this->command());

        self::assertFalse($item->isDefault);
    }

    /**
     * Le plafond est desormais tenu par l'agregat. Ce test verifie qu'il remonte bien jusqu'a
     * la frontiere HTTP, et surtout que rien n'est ni enregistre ni publie.
     */
    public function testHandleRejectsTheSixthAddress(): void
    {
        $customer = $this->aCustomerWithAddresses(
            self::CUSTOMER_ID,
            '550e8400-e29b-41d4-a716-446655440021',
            '550e8400-e29b-41d4-a716-446655440022',
            '550e8400-e29b-41d4-a716-446655440023',
            '550e8400-e29b-41d4-a716-446655440024',
            '550e8400-e29b-41d4-a716-446655440025',
        );

        $this->arrange($customer);
        $this->repository->expects($this->never())->method('save');

        $this->expectException(AddressLimitReachedException::class);

        try {
            $this->handler->handle($this->command());
        } finally {
            self::assertCount(Customer::MAX_ADDRESSES, $customer->getAddresses());
            self::assertSame([], $this->publishedEvents);
        }
    }

    public function testHandleRejectsAnUnknownCustomer(): void
    {
        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('nextAddressIdentity')
            ->willReturn(AddressId::fromString(self::ADDRESS_ID));
        $this->repository->expects($this->once())->method('findById')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->clock->expects($this->never())->method('now');

        $this->expectException(AddressNotFoundException::class);

        try {
            $this->handler->handle($this->command());
        } finally {
            self::assertSame([], $this->publishedEvents);
        }
    }

    private function arrange(Customer $customer): void
    {
        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('nextAddressIdentity')
            ->willReturn(AddressId::fromString(self::ADDRESS_ID));
        $this->repository->expects($this->once())->method('findById')->willReturn($customer);

        // `now()` est evalue comme argument de `addAddress()`, donc avant que l'agregat ne
        // rejette le sixieme ajout : il est appele une fois y compris sur le chemin d'echec.
        $this->clock->expects($this->once())->method('now')
            ->willReturn(new DateTimeImmutable('2025-02-01 10:00:00'));
    }

    private function command(): CreateAddressCommand
    {
        return new CreateAddressCommand(
            ownerId: self::CUSTOMER_ID,
            label: 'Bureau',
            firstname: 'Jane',
            lastname: 'Roe',
            company: 'Acme',
            street: '9 rue Neuve',
            zipCode: '69000',
            city: 'Lyon',
            country: 'France',
            phone: '0102030405',
        );
    }
}
