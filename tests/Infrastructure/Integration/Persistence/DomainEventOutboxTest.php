<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence;

use App\Application\Shared\Port\DomainEventBusInterface;
use App\Domain\Catalog\Event\Category\CategoryCreatedEvent;
use App\Domain\Catalog\Event\Product\ProductCreatedEvent;
use App\Domain\Catalog\Model\Category;
use App\Infrastructure\Symfony\Messenger\Transport\MongoOutboxReceivedStamp;
use DateTimeImmutable;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Throwable;

/**
 * Ce que ce test protege : **l'agregat et les evenements qu'il libere sont commites
 * ensemble, ou aucun ne l'est**.
 *
 * C'est la propriete pour laquelle l'outbox existe, et c'est aussi celle qui disparaitrait
 * le plus silencieusement. Si `MongoDomainEventBus` ecrivait par le pilote — un
 * `insertOne()` direct, ou un transport Messenger qui `send()` immediatement — tout
 * continuerait de fonctionner : les evenements arriveraient dans la collection, le worker
 * les consommerait, aucune erreur nulle part. Seul le cas du rollback revelerait la faute,
 * en publiant un `CategoryCreatedEvent` pour une categorie qui n'existe pas.
 *
 * Les deux cas de rollback ci-dessous sont donc a lire comme ceux de
 * `MongoTransactionalTest` : ils echouent si l'ecriture quitte l'unite de travail.
 */
final class DomainEventOutboxTest extends MongoPersistenceTestCase
{
    private const string OUTBOX = 'domain_event_outbox';

    private DomainEventBusInterface $eventBus;

    private TransportInterface $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();

        $eventBus = $container->get(DomainEventBusInterface::class);
        self::assertInstanceOf(DomainEventBusInterface::class, $eventBus);
        $this->eventBus = $eventBus;

        $transport = $container->get('messenger.transport.domain_events');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $this->transport = $transport;
    }

    public function testAnEventPublishedInTheTransactionIsCommittedWithItsAggregate(): void
    {
        $category = $this->aCategory('Guitares electriques');

        $this->transactional->transactional(function () use ($category): void {
            $this->categories->save($category);
            $this->eventBus->publishAll($category->releaseEvents());
        });

        $this->assertSame(1, $this->countOutbox());

        $row = $this->firstOutboxRow();
        $this->assertSame('domain_events', $row['queueName']);
        $this->assertSame('shop.catalog.category.created', $row['eventName']);
        $this->assertSame($category->getId()->toString(), $row['aggregateId']);
        $this->assertNotSame('', $row['eventId']);
        $this->assertNull($row['deliveredAt']);
    }

    public function testARollbackTakesTheEventsWithIt(): void
    {
        $first = $this->aCategory('Basses');
        $duplicate = $this->aCategory('Basses');

        $failure = $this->transactionalFailure(function () use ($first, $duplicate): void {
            $this->categories->save($first);
            $this->eventBus->publishAll($first->releaseEvents());

            // Meme titre : l'index unique fait echouer le flush entier.
            $this->categories->save($duplicate);
            $this->eventBus->publishAll($duplicate->releaseEvents());
        });

        $this->assertInstanceOf(Throwable::class, $failure);
        $this->assertSame(0, $this->countIn('category', ['title' => 'Basses']));
        $this->assertSame(
            0,
            $this->countOutbox(),
            'Un evenement a survecu au rollback de son agregat : la publication a quitte le flush transactionnel.',
        );
    }

    public function testAnExceptionRaisedByTheCallbackPublishesNothing(): void
    {
        $category = $this->aCategory('Amplis');

        $failure = $this->transactionalFailure(function () use ($category): void {
            $this->categories->save($category);
            $this->eventBus->publishAll($category->releaseEvents());

            throw new RuntimeException('Echec metier apres publication.');
        });

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame(0, $this->countIn('category', ['title' => 'Amplis']));
        $this->assertSame(0, $this->countOutbox());
    }

    public function testTwoAggregatesPublishTheirEventsInTheSameCommit(): void
    {
        $category = $this->aCategory('Pedales');

        $this->transactional->transactional(function () use ($category): void {
            $this->categories->save($category);
            $this->eventBus->publishAll($category->releaseEvents());
        });

        $product = $this->aProduct('Overdrive', $category->getId());

        $this->transactional->transactional(function () use ($product, $category): void {
            $this->products->save($product);
            $category->increaseProductCount(new DateTimeImmutable());
            $this->categories->save($category);
            $this->eventBus->publishAll($product->releaseEvents());
        });

        $names = array_map(
            static fn (array $row): string => (string) $row['eventName'],
            iterator_to_array($this->outboxRows()),
        );

        $this->assertSame(
            ['shop.catalog.category.created', 'shop.catalog.product.created'],
            $names,
        );
    }

    public function testTheTransportRestoresTheEventThatWasPublished(): void
    {
        $category = $this->aCategory('Micros');

        $this->transactional->transactional(function () use ($category): void {
            $this->categories->save($category);
            $this->eventBus->publishAll($category->releaseEvents());
        });

        $envelopes = iterator_to_array($this->transport->get());
        $this->assertCount(1, $envelopes);

        $envelope = $envelopes[0];
        $this->assertInstanceOf(Envelope::class, $envelope);

        $message = $envelope->getMessage();
        $this->assertInstanceOf(CategoryCreatedEvent::class, $message);
        $this->assertSame($category->getId()->toString(), $message->aggregateId());
        $this->assertSame($this->firstOutboxRow()['eventId'], $message->eventId());

        // Sans ce tampon, le RoutableMessageBus du worker retomberait sur command.bus.
        $busName = $envelope->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $busName);
        $this->assertSame('event.bus', $busName->getBusName());

        $this->assertInstanceOf(MongoOutboxReceivedStamp::class, $envelope->last(MongoOutboxReceivedStamp::class));
    }

    public function testAClaimedMessageIsNotHandedOutASecondTime(): void
    {
        $this->publishAlone($this->aCategory('Cordes'));

        $this->assertCount(1, iterator_to_array($this->transport->get()));
        $this->assertCount(
            0,
            iterator_to_array($this->transport->get()),
            "Le message reserve a ete redistribue : la reservation n'est pas atomique.",
        );
    }

    public function testAckRemovesTheMessageFromTheOutbox(): void
    {
        $this->publishAlone($this->aCategory('Mediators'));

        $envelopes = iterator_to_array($this->transport->get());
        $this->assertCount(1, $envelopes);

        $this->transport->ack($envelopes[0]);

        $this->assertSame(0, $this->countOutbox());
    }

    public function testAMessageAbandonedByItsWorkerIsHandedOutAgain(): void
    {
        $this->publishAlone($this->aCategory('Sangles'));

        $this->assertCount(1, iterator_to_array($this->transport->get()));

        // Simule un worker tue apres la reservation : sa date recule au-dela du
        // `redeliver_timeout` de 3600 s declare dans messenger.yaml.
        $this->database()->selectCollection(self::OUTBOX)->updateMany(
            [],
            ['$set' => ['deliveredAt' => new UTCDateTime(new DateTimeImmutable('-2 hours'))]],
        );

        $this->assertCount(
            1,
            iterator_to_array($this->transport->get()),
            "Un message abandonne n'est jamais repris : un worker tue perdrait son evenement.",
        );
    }

    public function testADelayedSendIsNotEligibleBeforeItsTime(): void
    {
        $event = new ProductCreatedEvent(
            $this->products->nextIdentity(),
            $this->categories->nextIdentity(),
            new DateTimeImmutable(),
        );

        $this->transport->send(
            (new Envelope($event))->with(new DelayStamp(60_000)),
        );

        $this->assertSame(1, $this->countOutbox());
        $this->assertCount(
            0,
            iterator_to_array($this->transport->get()),
            'Un message differe a ete servi avant son echeance : le retry perdrait son delai.',
        );
    }

    private function publishAlone(Category $category): void
    {
        $this->transactional->transactional(function () use ($category): void {
            $this->categories->save($category);
            $this->eventBus->publishAll($category->releaseEvents());
        });
    }

    private function transactionalFailure(callable $operation): ?Throwable
    {
        try {
            $this->transactional->transactional($operation);
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function outboxRows(): iterable
    {
        return $this->database()->selectCollection(self::OUTBOX)->find(
            [],
            ['sort' => ['availableAt' => 1, '_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function firstOutboxRow(): array
    {
        $rows = iterator_to_array($this->outboxRows());
        self::assertNotEmpty($rows, "Aucune ligne dans l'outbox.");

        $row = reset($rows);
        self::assertIsArray($row);

        return $row;
    }

    private function countOutbox(): int
    {
        return $this->countIn(self::OUTBOX, []);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function countIn(string $collection, array $filter): int
    {
        return $this->database()->selectCollection($collection)->countDocuments($filter);
    }
}
