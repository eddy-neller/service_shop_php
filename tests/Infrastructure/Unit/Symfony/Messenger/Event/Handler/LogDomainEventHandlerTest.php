<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Event\Handler;

use App\Domain\Catalog\Event\Category\CategoryCreatedEvent;
use App\Domain\Catalog\Event\Category\CategoryMovedEvent;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Infrastructure\Symfony\Messenger\Event\Handler\LogDomainEventHandler;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogDomainEventHandlerTest extends TestCase
{
    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440000';

    public function testItLogsNameIdentityAggregateAndDate(): void
    {
        $event = $this->createdEvent();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Domain event handled', [
                'event' => 'shop.catalog.category.created',
                'event_id' => $event->eventId(),
                'aggregate_id' => self::CATEGORY_ID,
                'occurred_on' => '2026-08-06 12:00:00',
            ]);

        (new LogDomainEventHandler($logger))($event);
    }

    /**
     * Les donnees du payload ne sont pas journalisees : l'identifiant de l'agregat suffit
     * a correler l'evenement, sans exposer ses relations internes.
     */
    public function testItDoesNotLogEventPayload(): void
    {
        $parentId = '550e8400-e29b-41d4-a716-446655440001';
        $event = new CategoryMovedEvent(
            CategoryId::fromString(self::CATEGORY_ID),
            CategoryId::fromString($parentId),
            new DateTimeImmutable('2026-08-06 12:00:00'),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Domain event handled',
                $this->callback(static fn (array $context): bool => !in_array($parentId, $context, true)),
            );

        (new LogDomainEventHandler($logger))($event);
    }

    private function createdEvent(): CategoryCreatedEvent
    {
        return new CategoryCreatedEvent(
            CategoryId::fromString(self::CATEGORY_ID),
            new DateTimeImmutable('2026-08-06 12:00:00'),
        );
    }
}
