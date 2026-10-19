<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence;

use App\Application\Shared\Port\ClockInterface;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Infrastructure\Persistence\Mongo\Inbox\ProcessedEventLedger;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use DateTimeImmutable;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;
use Throwable;

/**
 * Ce que ce test protege : **le marquage vit et meurt avec l'effet qu'il atteste**.
 *
 * C'est la seule propriete pour laquelle l'inbox merite d'exister. Un marquage engage par une
 * ecriture propre fonctionnerait en apparence — la ligne arriverait, les doublons seraient
 * ecartes — mais survivrait au rollback de l'effet : le message passerait pour traite alors qu'il
 * n'a rien produit, et son effet serait perdu definitivement, sans erreur nulle part. Seul le cas
 * du rollback ci-dessous revele la faute.
 */
final class ProcessedEventLedgerTest extends MongoPersistenceTestCase
{
    private const string COLLECTION = 'processed_event';

    private const string EVENT_ID = '018f3a40-0000-7000-8000-000000000001';

    private const string HANDLER = 'App\\Tests\\Fake\\SomeHandler';

    private const string OTHER_HANDLER = 'App\\Tests\\Fake\\OtherHandler';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private ProcessedEventLedgerInterface $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        // L'adaptateur est construit ici plutot que tire du conteneur : c'est lui qu'on verifie,
        // pas son cablage. Les deux chemins d'ecriture sont exerces directement, sans passer par
        // un handler qui en masquerait l'ordre.
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $this->ledger = new ProcessedEventLedger($this->documentManager, $clock);
    }

    public function testItRemembersAProcessedEvent(): void
    {
        self::assertFalse($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));

        $this->mark(self::HANDLER);

        self::assertTrue($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));
    }

    public function testTheMarkingIsCommittedWithTheBusinessWrite(): void
    {
        $customerId = $this->customers->nextIdentity();

        $this->transactional->transactional(function () use ($customerId): void {
            $this->customers->save($this->aCustomer($customerId));

            $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);
        });

        self::assertTrue($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));
        self::assertNotNull($this->customers->findById($customerId));
    }

    /**
     * **Le test qui justifie le `persist()`.** L'ecriture metier echoue, donc le marquage disparait
     * avec elle : le message sera rejoue, et l'effet applique une seule fois.
     */
    public function testTheMarkingIsRolledBackWithTheBusinessWrite(): void
    {
        $customerId = $this->customers->nextIdentity();

        $failure = $this->transactionalFailure(function () use ($customerId): void {
            $this->customers->save($this->aCustomer($customerId));

            $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);

            throw new RuntimeException('effet metier interrompu');
        });

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertFalse($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));
        self::assertNull($this->customers->findById($customerId));
        self::assertSame(0, $this->lineCount());
    }

    /**
     * Une redelivrance qui franchirait la garde se heurte a l'index unique : le flush echoue, donc
     * la transaction entiere, et l'effet n'est pas applique deux fois. La ligne d'origine, elle,
     * reste intacte — `hasProcessed()` existe pour ne pas en arriver la sur le chemin nominal.
     */
    public function testASecondMarkingFailsItsTransactionAndLeavesTheFirstLineIntact(): void
    {
        $this->mark(self::HANDLER);
        $first = $this->line();

        $failure = $this->transactionalFailure(function (): void {
            $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);
        });

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertSame(1, $this->lineCount());
        self::assertEquals($first['processedAt'], $this->line()['processedAt']);
        self::assertEquals($first['expiresAt'], $this->line()['expiresAt']);
    }

    /**
     * Le second chemin d'ecriture, pour les handlers qui ne detiennent pas la transaction de leur
     * effet : il engage seul, sans rien attendre de personne.
     */
    public function testTheFlushingVariantIsDurableWithoutATransaction(): void
    {
        $this->ledger->markProcessedAndFlush(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);

        self::assertTrue($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));
        self::assertSame(1, $this->lineCount());
        self::assertSame(30, $this->retentionDaysOf(self::HANDLER));
    }

    /**
     * **Le test qui justifie les deux methodes.** `markProcessed()` seul n'ecrit rien : sans flush
     * derriere, le document persiste est oublie, sans erreur ni log. C'est le piege que le suffixe
     * `AndFlush` existe pour rendre visible — et le symptome serait une deduplication qui ne
     * deduplique pas.
     */
    public function testMarkProcessedAloneWritesNothing(): void
    {
        $this->ledger->markProcessed(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);

        self::assertFalse($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));
        self::assertSame(0, $this->lineCount());

        // Le document reste programme dans l'unite de travail : la vider, pour qu'un flush
        // ulterieur ne l'ecrive pas apres coup.
        $this->documentManager->clear();
    }

    /**
     * La contrepartie de cette durabilite immediate : la ligne **ne participe a aucune transaction
     * suivante**. C'est pourquoi ce chemin ne s'appelle qu'**apres** l'effet — marquer avant
     * echangerait un doublon au rejeu contre une perte definitive.
     */
    public function testTheFlushingVariantSurvivesALaterRollback(): void
    {
        $customerId = $this->customers->nextIdentity();

        $this->ledger->markProcessedAndFlush(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);

        $failure = $this->transactionalFailure(function () use ($customerId): void {
            $this->customers->save($this->aCustomer($customerId));

            throw new RuntimeException('effet metier interrompu');
        });

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertNull($this->customers->findById($customerId));
        self::assertTrue($this->ledger->hasProcessed(self::EVENT_ID, self::HANDLER));
    }

    /**
     * Hors transaction aussi, c'est l'index unique qui garantit l'unicite : le second appel echoue
     * et laisse la premiere ligne seule. Le message est alors rejoue, et la garde le voit traite.
     */
    public function testASecondFlushingMarkingIsRejectedByTheUniqueIndex(): void
    {
        $this->ledger->markProcessedAndFlush(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);

        $failure = null;

        try {
            $this->ledger->markProcessedAndFlush(self::EVENT_ID, self::HANDLER, ProcessedEventSource::Bus);
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            // Un flush interrompu laisse l'unite de travail incoherente : la vider avant la suite.
            $this->documentManager->clear();
        }

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertSame(1, $this->lineCount());
    }

    /**
     * Un meme evenement peut declencher plusieurs reactions : la cle porte donc le handler, et
     * marquer l'une ne doit jamais faire sauter l'autre.
     */
    public function testTheSameEventCanBeProcessedByTwoHandlers(): void
    {
        $this->mark(self::HANDLER);

        self::assertFalse($this->ledger->hasProcessed(self::EVENT_ID, self::OTHER_HANDLER));

        $this->mark(self::OTHER_HANDLER);

        self::assertTrue($this->ledger->hasProcessed(self::EVENT_ID, self::OTHER_HANDLER));
        self::assertSame(2, $this->lineCount());
    }

    /**
     * La retention vit dans la provenance, pas dans la collection : c'est ce qui permet aux deux
     * flux d'en partager une seule. L'index TTL supprime la ligne a cette date ; on verifie la
     * date, MongoDB ne balayant qu'environ chaque minute.
     */
    public function testEachSourceCarriesItsOwnExpiry(): void
    {
        $this->mark(self::HANDLER, ProcessedEventSource::Bus);
        $this->mark(self::OTHER_HANDLER, ProcessedEventSource::Outbox);

        self::assertSame(30, $this->retentionDaysOf(self::HANDLER));
        self::assertSame(7, $this->retentionDaysOf(self::OTHER_HANDLER));
        self::assertSame('bus.identity', $this->line()['source']);
    }

    private function mark(string $handler, ProcessedEventSource $source = ProcessedEventSource::Bus): void
    {
        $this->transactional->transactional(function () use ($handler, $source): void {
            $this->ledger->markProcessed(self::EVENT_ID, $handler, $source);
        });
    }

    private function aCustomer(CustomerId $customerId): Customer
    {
        return Customer::create(
            $customerId,
            new DateTimeImmutable(),
            UserAccountId::fromString(self::ACCOUNT_ID),
        );
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
