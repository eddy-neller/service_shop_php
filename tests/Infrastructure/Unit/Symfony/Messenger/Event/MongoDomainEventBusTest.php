<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Event;

use App\Application\Shared\Port\ClockInterface;
use App\Domain\Catalog\Event\Category\CategoryCreatedEvent;
use App\Domain\Catalog\Event\Product\ProductCreatedEvent;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Infrastructure\Persistence\Mongo\Outbox\DomainEventDocument;
use App\Infrastructure\Persistence\Mongo\Outbox\DomainEventOutbox;
use App\Infrastructure\Symfony\Messenger\Event\MongoDomainEventBus;
use App\Infrastructure\Symfony\Messenger\Event\PublishedDomainEventCollector;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class MongoDomainEventBusTest extends TestCase
{
    private DocumentManager&MockObject $documentManager;

    private SerializerInterface&MockObject $serializer;

    private PublishedDomainEventCollector $collector;

    private MongoDomainEventBus $eventBus;

    protected function setUp(): void
    {
        $this->documentManager = $this->createMock(DocumentManager::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->collector = new PublishedDomainEventCollector();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-06 12:00:00'));

        $this->eventBus = new MongoDomainEventBus(
            new DomainEventOutbox($this->documentManager, $clock),
            $this->collector,
            $this->serializer,
        );
    }

    public function testPublishAllEnqueuesEveryEventWithoutFlushing(): void
    {
        $first = $this->categoryCreated();
        $second = $this->productCreated();
        $envelopes = [];
        $documents = [];

        $this->serializer->expects($this->exactly(2))
            ->method('encode')
            ->willReturnCallback(static function (Envelope $envelope) use (&$envelopes): array {
                $envelopes[] = $envelope;

                return [
                    'body' => sprintf('encoded-%d', count($envelopes)),
                    'headers' => ['type' => $envelope->getMessage()::class],
                ];
            });
        $this->documentManager->expects($this->exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $document) use (&$documents): void {
                self::assertInstanceOf(DomainEventDocument::class, $document);
                $documents[] = $document;
            });
        $this->documentManager->expects($this->never())->method('flush');

        $this->eventBus->publishAll([$first, $second]);

        self::assertCount(2, $envelopes);
        foreach ($envelopes as $envelope) {
            $stamp = $envelope->last(BusNameStamp::class);
            self::assertInstanceOf(BusNameStamp::class, $stamp);
            self::assertSame('event.bus', $stamp->getBusName());
        }

        self::assertSame(DomainEventOutbox::DEFAULT_QUEUE, $documents[0]->queueName);
        self::assertSame('encoded-1', $documents[0]->body);
        self::assertSame(['type' => CategoryCreatedEvent::class], $documents[0]->headers);
        self::assertSame($first->eventName(), $documents[0]->eventName);
        self::assertSame($first->eventId(), $documents[0]->eventId);
        self::assertSame($first->aggregateId(), $documents[0]->aggregateId);
        self::assertSame($first->occurredOn(), $documents[0]->occurredOn);

        self::assertSame('encoded-2', $documents[1]->body);
        self::assertSame(['type' => ProductCreatedEvent::class], $documents[1]->headers);
        self::assertSame($second->eventId(), $documents[1]->eventId);
    }

    public function testPublishAllDoesNothingWithoutEvents(): void
    {
        $this->serializer->expects($this->never())->method('encode');
        $this->documentManager->expects($this->never())->method('persist');

        $this->eventBus->publishAll([]);

        self::assertSame([], $this->collector->release());
    }

    public function testPublishAllRecordsEventsForSynchronousCacheInvalidation(): void
    {
        $first = $this->categoryCreated();
        $second = $this->productCreated();

        $this->serializer->expects($this->exactly(2))
            ->method('encode')
            ->willReturn(['body' => 'encoded', 'headers' => []]);
        $this->documentManager->expects($this->exactly(2))->method('persist');

        $this->eventBus->publishAll([$first, $second]);

        self::assertSame([$first, $second], $this->collector->release());
    }

    private function categoryCreated(): CategoryCreatedEvent
    {
        return new CategoryCreatedEvent(
            CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            new DateTimeImmutable('2026-08-06 12:00:00'),
        );
    }

    private function productCreated(): ProductCreatedEvent
    {
        return new ProductCreatedEvent(
            ProductId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            new DateTimeImmutable('2026-08-06 12:00:00'),
        );
    }
}
