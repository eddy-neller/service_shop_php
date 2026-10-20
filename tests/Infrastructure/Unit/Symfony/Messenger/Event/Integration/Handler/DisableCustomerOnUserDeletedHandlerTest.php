<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Event\Integration\Handler;

use App\Application\Customer\UseCase\Command\DisableCustomerForAccount\DisableCustomerForAccountCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Command\CommandInterface;
use App\Infrastructure\Symfony\Messenger\Event\Integration\Handler\DisableCustomerOnUserDeletedHandler;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserDeletedIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DisableCustomerOnUserDeletedHandlerTest extends TestCase
{
    private const string EVENT_ID = '018f3a40-0000-7000-8000-000000000002';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private CommandBusInterface&MockObject $commandBus;

    private ProcessedEventLedgerInterface&MockObject $ledger;

    private bool $dispatched = false;

    private DisableCustomerOnUserDeletedHandler $handler;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->ledger = $this->createMock(ProcessedEventLedgerInterface::class);
        $this->dispatched = false;

        $this->handler = new DisableCustomerOnUserDeletedHandler($this->commandBus, $this->ledger);
    }

    public function testItTranslatesTheEventIntoADisablingCommand(): void
    {
        $this->ledger->method('hasProcessed')->willReturn(false);
        $this->expectTheCommandToBeDispatched();
        $this->ledger->expects($this->once())->method('markProcessedAndFlush')
            ->with(self::EVENT_ID, DisableCustomerOnUserDeletedHandler::class, ProcessedEventSource::Bus)
            ->willReturnCallback(function (): void {
                self::assertTrue($this->dispatched, "L'inbox doit etre marquee apres le dispatch.");
            });

        ($this->handler)($this->anEvent());
    }

    public function testItSkipsAnEventItHasAlreadyProcessed(): void
    {
        $this->ledger->expects($this->once())->method('hasProcessed')
            ->with(self::EVENT_ID, DisableCustomerOnUserDeletedHandler::class)
            ->willReturn(true);
        $this->commandBus->expects($this->never())->method('dispatch');
        $this->ledger->expects($this->never())->method('markProcessedAndFlush');

        ($this->handler)($this->anEvent());
    }

    public function testItMarksNothingWhenTheCommandFails(): void
    {
        $this->ledger->method('hasProcessed')->willReturn(false);
        $this->commandBus->expects($this->once())->method('dispatch')
            ->willThrowException(new RuntimeException('mongodb indisponible'));
        $this->ledger->expects($this->never())->method('markProcessedAndFlush');

        $this->expectException(RuntimeException::class);

        ($this->handler)($this->anEvent());
    }

    private function expectTheCommandToBeDispatched(): void
    {
        $this->commandBus->expects($this->once())->method('dispatch')
            ->willReturnCallback(function (CommandInterface $command): null {
                self::assertInstanceOf(DisableCustomerForAccountCommand::class, $command);
                self::assertSame(self::ACCOUNT_ID, $command->userAccountId);
                $this->dispatched = true;

                return null;
            });
    }

    private function anEvent(): UserDeletedIntegrationEvent
    {
        return new UserDeletedIntegrationEvent(
            self::EVENT_ID,
            self::ACCOUNT_ID,
            new DateTimeImmutable('2026-09-15 12:00:00'),
        );
    }
}
