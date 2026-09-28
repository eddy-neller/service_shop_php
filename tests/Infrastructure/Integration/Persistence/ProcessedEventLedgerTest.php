<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence;

use App\Application\Shared\Port\ClockInterface;
use App\Infrastructure\Persistence\Mongo\Inbox\ProcessedEventLedger;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use DateTimeImmutable;
use MongoDB\BSON\UTCDateTime;

/**
 * Ce que ce test protege : **le marquage est idempotent, et deux flux cohabitent sans se voir**.
 *
 * L'inbox n'a d'interet que rejouee : un test qui ne marquerait qu'une fois passerait au vert
 * meme si l'index unique manquait ou si un second marquage levait une exception — c'est-a-dire
 * precisement dans le cas ou elle doit servir.
 */
final class ProcessedEventLedgerTest extends MongoPersistenceTestCase
{
    private const string COLLECTION = 'processed_event';

    private const string EVENT_ID = '018f3a40-0000-7000-8000-000000000001';

    private const string HANDLER = 'App\\Tests\\Fake\\SomeHandler';

    private ProcessedEventLedgerInterface $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        // L'adaptateur est construit ici plutot que tire du conteneur : personne ne l'injecte
        // encore, donc son alias est supprime a la compilation. Le cablage sera exerce par le
        // handler du bus, qui arrive avec le transport.
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $this->ledger = new ProcessedEventLedger($this->documentManager, $clock);
    }

    public function testItRemembersAProcessedEvent(): void
    {
        self::assertFalse($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));

        $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);

        self::assertTrue($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));
    }

    /**
     * Le cas qui compte : une redelivrance. Le second marquage ne doit ni lever, ni dupliquer la
     * ligne, ni repousser l'expiration posee par le premier.
     */
    public function testMarkingTwiceKeepsTheFirstLineUntouched(): void
    {
        $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);
        $first = $this->line();

        $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);

        self::assertSame(1, $this->lineCount());
        self::assertEquals($first['processedAt'], $this->line()['processedAt']);
        self::assertEquals($first['expiresAt'], $this->line()['expiresAt']);
    }

    /**
     * Un meme evenement peut declencher plusieurs reactions : la cle porte donc le handler, et
     * marquer l'une ne doit jamais faire sauter l'autre.
     */
    public function testTheSameEventCanBeProcessedByTwoHandlers(): void
    {
        $other = 'App\\Tests\\Fake\\OtherHandler';

        $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);

        self::assertFalse($this->ledger->hasProcessed(self::EVENT_ID, $other));

        $this->ledger->markProcessed(self::EVENT_ID, $other, ProcessedEventSource::Bus);

        self::assertTrue($this->ledger->hasProcessed(self::EVENT_ID, $other));
        self::assertSame(2, $this->lineCount());
    }

    /**
     * La retention vit dans la provenance, pas dans la collection : c'est ce qui permet aux deux
     * flux d'en partager une seule. L'index TTL supprime la ligne a cette date ; on verifie la
     * date, MongoDB ne balayant qu'environ chaque minute.
     */
    public function testEachSourceCarriesItsOwnExpiry(): void
    {
        $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);
        $this->ledger->markProcessed(self::EVENT_ID, 'App\\Tests\\Fake\\OutboxHandler', ProcessedEventSource::Outbox);

        self::assertSame(30, $this->retentionDaysOf(self::HANDLER));
        self::assertSame(7, $this->retentionDaysOf('App\\Tests\\Fake\\OutboxHandler'));
        self::assertSame('bus.identity', $this->line()['source']);
    }

    private function retentionDaysOf(string $handler): int
    {
        $line = $this->line($handler);
        $processedAt = $this->toDateTime($line['processedAt']);
        $expiresAt = $this->toDateTime($line['expiresAt']);

        return (int) $processedAt->diff($expiresAt)->days;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $handler = self::HANDLER): array
    {
        $document = $this->database()->selectCollection(self::COLLECTION)->findOne(
            ['eventId' => self::EVENT_ID, 'handler' => $handler],
            ['typeMap' => ['root' => 'array', 'document' => 'array']],
        );

        self::assertIsArray($document);

        return $document;
    }

    private function lineCount(): int
    {
        return $this->database()->selectCollection(self::COLLECTION)->countDocuments();
    }

    private function toDateTime(mixed $value): DateTimeImmutable
    {
        self::assertInstanceOf(UTCDateTime::class, $value);

        return DateTimeImmutable::createFromMutable($value->toDateTime());
    }
}
