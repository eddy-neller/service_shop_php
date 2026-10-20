<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Event\Integration\Handler;

use App\Application\Customer\UseCase\Command\ProvisionCustomerForAccount\ProvisionCustomerForAccountCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Command\CommandInterface;
use App\Infrastructure\Symfony\Messenger\Event\Integration\Handler\ProvisionCustomerOnUserCreatedHandler;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserCreatedByAdminIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserRegisteredIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProvisionCustomerOnUserCreatedHandlerTest extends TestCase
{
    private const string EVENT_ID = '018f3a40-0000-7000-8000-000000000001';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private CommandBusInterface&MockObject $commandBus;

    private ProcessedEventLedgerInterface&MockObject $ledger;

    /** Vrai des que la commande est partie : voir `testItMarksTheInboxAfterTheEffect`. */
    private bool $dispatched = false;

    private ProvisionCustomerOnUserCreatedHandler $handler;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->ledger = $this->createMock(ProcessedEventLedgerInterface::class);
        $this->dispatched = false;

        $this->handler = new ProvisionCustomerOnUserCreatedHandler($this->commandBus, $this->ledger);
    }

    /**
     * Les deux types du contrat declenchent la meme commande : le shop a besoin du meme client,
     * qu'un visiteur s'inscrive ou qu'un administrateur cree le compte.
     */
    #[DataProvider('creationEvents')]
    public function testItTranslatesTheEventIntoAProvisioningCommand(string $method, UserIntegrationEvent $event): void
    {
        $this->ledger->method('hasProcessed')->willReturn(false);
        $this->expectTheCommandToBeDispatched();
        $this->ledger->expects($this->once())->method('markProcessedAndFlush')
            ->with(self::EVENT_ID, ProvisionCustomerOnUserCreatedHandler::class, ProcessedEventSource::Bus);

        $this->handler->{$method}($event);
    }

    /**
     * @return iterable<string, array{string, UserIntegrationEvent}>
     */
    public static function creationEvents(): iterable
    {
        yield 'inscription' => ['onUserRegistered', new UserRegisteredIntegrationEvent(
            self::EVENT_ID,
            self::ACCOUNT_ID,
            new DateTimeImmutable('2026-09-15 12:00:00'),
        )];

        yield 'creation admin' => ['onUserCreatedByAdmin', new UserCreatedByAdminIntegrationEvent(
            self::EVENT_ID,
            self::ACCOUNT_ID,
            new DateTimeImmutable('2026-09-15 12:00:00'),
        )];
    }

    /**
     * **L'ordre porte la garantie.** Marquer avant l'effet echangerait un doublon au rejeu — que
     * la convergence du cas d'usage absorbe — contre une perte definitive.
     */
    public function testItMarksTheInboxAfterTheEffect(): void
    {
        $this->ledger->method('hasProcessed')->willReturn(false);
        $this->expectTheCommandToBeDispatched();

        $this->ledger->expects($this->once())->method('markProcessedAndFlush')
            ->willReturnCallback(function (): void {
                self::assertTrue($this->dispatched, "L'inbox doit etre marquee apres le dispatch.");
            });

        $this->handler->onUserRegistered($this->anEvent());
    }

    /** Livraison au-moins-une-fois : un evenement deja traite ne doit rien declencher. */
    public function testItSkipsAnEventItHasAlreadyProcessed(): void
    {
        $this->ledger->expects($this->once())->method('hasProcessed')
            ->with(self::EVENT_ID, ProvisionCustomerOnUserCreatedHandler::class)
            ->willReturn(true);
        $this->commandBus->expects($this->never())->method('dispatch');
        $this->ledger->expects($this->never())->method('markProcessedAndFlush');

        $this->handler->onUserRegistered($this->anEvent());
    }

    /**
     * Si l'effet echoue, rien n'est marque : le message doit etre rejoue. Un marquage pose malgre
     * l'echec ferait passer l'evenement pour traite et perdrait son effet pour de bon.
     */
    public function testItMarksNothingWhenTheCommandFails(): void
    {
        $this->ledger->method('hasProcessed')->willReturn(false);
        $this->commandBus->expects($this->once())->method('dispatch')
            ->willThrowException(new RuntimeException('mongodb indisponible'));
        $this->ledger->expects($this->never())->method('markProcessedAndFlush');

        $this->expectException(RuntimeException::class);

        $this->handler->onUserRegistered($this->anEvent());
    }

    private function expectTheCommandToBeDispatched(): void
    {
        $this->commandBus->expects($this->once())->method('dispatch')
            ->willReturnCallback(function (CommandInterface $command): null {
                self::assertInstanceOf(ProvisionCustomerForAccountCommand::class, $command);
                self::assertSame(self::ACCOUNT_ID, $command->userAccountId);
                $this->dispatched = true;

                return null;
            });
    }

    private function anEvent(): UserRegisteredIntegrationEvent
    {
        return new UserRegisteredIntegrationEvent(
            self::EVENT_ID,
            self::ACCOUNT_ID,
            new DateTimeImmutable('2026-09-15 12:00:00'),
        );
    }
}
