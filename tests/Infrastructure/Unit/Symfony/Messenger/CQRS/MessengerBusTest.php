<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\CQRS;

use App\Application\Shared\CQRS\Command\CommandInterface;
use App\Application\Shared\CQRS\Query\QueryInterface;
use App\Infrastructure\Symfony\Messenger\CQRS\HandledResultExtractor;
use App\Infrastructure\Symfony\Messenger\CQRS\MessengerCommandBus;
use App\Infrastructure\Symfony\Messenger\CQRS\MessengerQueryBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class MessengerBusTest extends TestCase
{
    public function testCommandBusReturnsTheHandlerResult(): void
    {
        $command = new class implements CommandInterface {
        };
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($command)
            ->willReturn(new Envelope($command, [new HandledStamp('result', 'handler')]));

        $bus = new MessengerCommandBus($messageBus, new HandledResultExtractor());

        self::assertSame('result', $bus->dispatch($command));
    }

    public function testQueryBusReturnsTheHandlerResult(): void
    {
        $query = new class implements QueryInterface {
        };
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($query)
            ->willReturn(new Envelope($query, [new HandledStamp(42, 'handler')]));

        $bus = new MessengerQueryBus($messageBus, new HandledResultExtractor());

        self::assertSame(42, $bus->dispatch($query));
    }
}
