<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Customer\UseCase\Command;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Command\ProvisionCustomerForAccount\ProvisionCustomerForAccountCommand;
use App\Application\Customer\UseCase\Command\ProvisionCustomerForAccount\ProvisionCustomerForAccountCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Event\Customer\CustomerCreatedEvent;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProvisionCustomerForAccountTest extends TestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private const string NOW = '2025-01-01 10:00:00';

    private CustomerRepositoryInterface&MockObject $repository;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private ProvisionCustomerForAccountCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->publishedEvents = [];

        // Horloge et transaction n'ont rien a verifier ici : des stubs, sinon PHPUnit signale
        // des mocks sans attente.
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
            ->willReturn(CustomerId::fromString(self::CUSTOMER_ID));

        $this->handler = new ProvisionCustomerForAccountCommandHandler(
            $this->repository,
            $clock,
            $transactional,
            $eventBus,
        );
    }

    public function testItCreatesAnActiveCustomerWhenTheAccountHasNone(): void
    {
        $this->repository->expects($this->once())->method('findByUserAccountId')->willReturn(null);

        $saved = null;
        $this->repository->expects($this->once())->method('save')
            ->willReturnCallback(function (Customer $customer) use (&$saved): void {
                $saved = $customer;
            });

        $this->handler->handle(new ProvisionCustomerForAccountCommand(self::ACCOUNT_ID));

        self::assertInstanceOf(Customer::class, $saved);
        self::assertSame(self::CUSTOMER_ID, $saved->getId()->toString());
        self::assertSame(self::ACCOUNT_ID, $saved->getUserAccountId()?->toString());
        self::assertTrue($saved->getStatus()->isActive());
        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(CustomerCreatedEvent::class, $this->publishedEvents[0]);
    }

    /**
     * Livraison au-moins-une-fois : le meme message peut arriver deux fois. Le second passage ne
     * doit produire ni ecriture ni evenement.
     */
    public function testItDoesNothingWhenTheAccountAlreadyHasACustomer(): void
    {
        $this->repository->expects($this->once())->method('findByUserAccountId')
            ->willReturn($this->anExistingCustomer());
        $this->repository->expects($this->never())->method('save');

        $this->handler->handle(new ProvisionCustomerForAccountCommand(self::ACCOUNT_ID));

        self::assertSame([], $this->publishedEvents);
    }

    /**
     * Le coeur de la commutativite : la suppression du compte a pu etre traitee en premier. Le
     * client desactive est une pierre tombale — ce handler ne doit pas la reactiver.
     */
    public function testItLeavesADisabledCustomerDisabled(): void
    {
        $customer = $this->anExistingCustomer();
        $customer->disable(new DateTimeImmutable('2025-01-02 10:00:00'));
        $customer->clearDomainEvents();

        $this->repository->expects($this->once())->method('findByUserAccountId')->willReturn($customer);
        $this->repository->expects($this->never())->method('save');

        $this->handler->handle(new ProvisionCustomerForAccountCommand(self::ACCOUNT_ID));

        self::assertTrue($customer->getStatus()->isDisabled());
        self::assertSame([], $this->publishedEvents);
    }

    private function anExistingCustomer(): Customer
    {
        $customer = Customer::create(
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable(self::NOW),
            UserAccountId::fromString(self::ACCOUNT_ID),
        );
        $customer->clearDomainEvents();

        return $customer;
    }
}
