<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Command\UpdateAddress\UpdateAddressCommand;
use App\Application\Customer\UseCase\Command\UpdateAddress\UpdateAddressCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Event\Address\AddressUpdatedEvent;
use App\Domain\Customer\Exception\AddressNotFoundException;
use App\Domain\Customer\Model\Customer;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UpdateAddressTest extends TestCase
{
    use CustomerBuilderTrait;

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ADDRESS_ID = '550e8400-e29b-41d4-a716-446655440020';

    private const string OTHER_CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440099';

    private CustomerRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private UpdateAddressCommandHandler $handler;

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

        $this->handler = new UpdateAddressCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleRewritesTheSuppliedFieldsAndPublishesTheEvent(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID, self::ADDRESS_ID);

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $item = $this->handler->handle($this->command(label: 'Bureau', city: 'Lyon'));

        self::assertSame('Bureau', $item->name);
        self::assertSame('Lyon', $item->city);

        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(AddressUpdatedEvent::class, $this->publishedEvents[0]);
    }

    /**
     * Mise a jour partielle : un champ absent de la commande conserve sa valeur.
     */
    public function testHandleKeepsTheFieldsLeftOutOfTheCommand(): void
    {
        $customer = $this->aCustomerWithAddresses(self::CUSTOMER_ID, self::ADDRESS_ID);

        $this->arrange($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $item = $this->handler->handle($this->command(label: 'Bureau'));

        self::assertSame('Bureau', $item->name);
        self::assertSame('Paris', $item->city);
        self::assertSame('1 rue de la Paix', $item->address);
        self::assertSame('0102030405', $item->phone);
    }

    /**
     * L'adresse d'autrui est **introuvable**, pas interdite : elle est cherchee dans le
     * client appelant, qui ne la contient pas. C'est ce qui remplace `ShopAddressVoter` et
     * fait passer la reponse de 403 a 404.
     */
    public function testHandleCannotReachTheAddressOfAnotherCustomer(): void
    {
        $customer = $this->aCustomerWithAddresses(self::OTHER_CUSTOMER_ID, self::ADDRESS_ID);

        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->clock->expects($this->never())->method('now');

        $this->expectException(AddressNotFoundException::class);

        try {
            $this->handler->handle($this->command(label: 'Bureau'));
        } finally {
            self::assertCount(1, $customer->getAddresses());
            self::assertSame([], $this->publishedEvents);
        }
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
            $this->handler->handle($this->command(label: 'Bureau'));
        } finally {
            self::assertSame([], $this->publishedEvents);
        }
    }

    private function arrange(Customer $customer): void
    {
        $this->transactional->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->repository->expects($this->once())->method('findById')->willReturn($customer);
        $this->clock->expects($this->once())->method('now')
            ->willReturn(new DateTimeImmutable('2025-02-01 10:00:00'));
    }

    private function command(?string $label = null, ?string $city = null): UpdateAddressCommand
    {
        return new UpdateAddressCommand(
            addressId: self::ADDRESS_ID,
            ownerId: self::CUSTOMER_ID,
            label: $label,
            firstname: null,
            lastname: null,
            company: null,
            street: null,
            zipCode: null,
            city: $city,
            country: null,
            phone: null,
        );
    }
}
