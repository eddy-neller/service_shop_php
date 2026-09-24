<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Command\DisableCustomerForAccount\DisableCustomerForAccountCommand;
use App\Application\Customer\UseCase\Command\DisableCustomerForAccount\DisableCustomerForAccountCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Event\Customer\CustomerCreatedEvent;
use App\Domain\Customer\Event\Customer\CustomerDisabledEvent;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisableCustomerForAccountTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string TOMBSTONE_ID = '550e8400-e29b-41d4-a716-446655440012';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private const string NOW = '2025-01-02 10:00:00';

    private CustomerRepositoryInterface&MockObject $repository;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private DisableCustomerForAccountCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->publishedEvents = [];

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

        $transactional = $this->createStub(TransactionalInterface::class);
        $transactional->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());

        $eventBus = $this->createStub(DomainEventBusInterface::class);
        $eventBus->method('publishAll')->willReturnCallback(function (array $events): void {
            $this->publishedEvents = [...$this->publishedEvents, ...$events];
        });

        $this->repository->method('nextIdentity')
            ->willReturn(CustomerId::fromString(self::TOMBSTONE_ID));

        $this->handler = new DisableCustomerForAccountCommandHandler(
            $this->repository,
            $clock,
            $transactional,
            $eventBus,
        );
    }

    public function testItDisablesTheCustomerOfTheAccount(): void
    {
        $customer = $this->anActiveCustomer();
        $this->repository->expects($this->once())->method('findByUserAccountId')->willReturn($customer);
        $this->repository->expects($this->once())->method('save')->with($customer);

        $this->handler->handle(new DisableCustomerForAccountCommand(self::ACCOUNT_ID));

        self::assertTrue($customer->getStatus()->isDisabled());
        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(CustomerDisabledEvent::class, $this->publishedEvents[0]);
    }

    /**
     * Le message arrive avant celui de la creation du compte, ou le client n'a jamais ete
     * provisionne : on pose une pierre tombale, pour qu'un provisionnement tardif ne cree pas
     * un client actif rattache a un compte supprime.
     */
    public function testItCreatesAnAlreadyDisabledCustomerWhenTheAccountHasNone(): void
    {
        $this->repository->expects($this->once())->method('findByUserAccountId')->willReturn(null);

        $saved = null;
        $this->repository->expects($this->once())->method('save')
            ->willReturnCallback(function (Customer $customer) use (&$saved): void {
                $saved = $customer;
            });

        $this->handler->handle(new DisableCustomerForAccountCommand(self::ACCOUNT_ID));

        self::assertInstanceOf(Customer::class, $saved);
        self::assertSame(self::TOMBSTONE_ID, $saved->getId()->toString());
        self::assertSame(self::ACCOUNT_ID, $saved->getUserAccountId()?->toString());
        self::assertTrue($saved->getStatus()->isDisabled());

        self::assertCount(2, $this->publishedEvents);
        self::assertInstanceOf(CustomerCreatedEvent::class, $this->publishedEvents[0]);
        self::assertInstanceOf(CustomerDisabledEvent::class, $this->publishedEvents[1]);
    }

    /**
     * Livraison au-moins-une-fois : le second passage ne doit rien ecrire, donc ne pas
     * incrementer le verrou optimiste ni republier d'evenement.
     */
    public function testItDoesNothingWhenTheCustomerIsAlreadyDisabled(): void
    {
        $customer = $this->anActiveCustomer();
        $customer->disable(new DateTimeImmutable('2025-01-01 12:00:00'));
        $customer->clearDomainEvents();

        $this->repository->expects($this->once())->method('findByUserAccountId')->willReturn($customer);
        $this->repository->expects($this->never())->method('save');

        $this->handler->handle(new DisableCustomerForAccountCommand(self::ACCOUNT_ID));

        self::assertSame([], $this->publishedEvents);
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
