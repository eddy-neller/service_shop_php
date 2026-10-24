<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Transport;

use App\Infrastructure\Symfony\Messenger\Transport\BusOutageTolerantTransport;
use AsyncAws\Core\Exception\Http\NetworkException;
use AsyncAws\Core\Result;
use AsyncAws\Core\Test\ResultMockFactory;
use Generator;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Throwable;

final class BusOutageTolerantTransportTest extends TestCase
{
    /** Le transport reellement decore en production : il porte les six capacites a conserver. */
    private AmazonSqsTransport&Stub $inner;

    private MockClock $clock;

    private TestHandler $logs;

    private Logger $logger;

    private BusOutageTolerantTransport $transport;

    protected function setUp(): void
    {
        $this->inner = $this->createStub(AmazonSqsTransport::class);
        $this->clock = new MockClock('2026-10-23 00:00:00');
        $this->logs = new TestHandler();
        $this->logger = new Logger('test', [$this->logs]);

        $this->transport = new BusOutageTolerantTransport($this->inner, $this->clock, $this->logger);
    }

    /**
     * Le bus injoignable, sous ses quatre formes : le consommateur attend au lieu de mourir. C'est
     * ce qui l'empeche de finir `FATAL` sous Supervisor apres une vingtaine de minutes de panne.
     */
    #[DataProvider('unreachableBus')]
    public function testItWaitsInsteadOfDyingWhenTheBusIsUnreachable(Throwable $cause): void
    {
        $this->inner->method('get')->willThrowException(new TransportException($cause->getMessage(), 0, $cause));

        self::assertSame([], $this->transport->get());
        self::assertSame(1, $this->secondsWaited());
        self::assertTrue($this->logs->hasWarningThatContains('Integration bus unreachable'));
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function unreachableBus(): iterable
    {
        yield 'hote introuvable (brique aws_local eteinte)' => [new NetworkException('Could not contact remote server.')];
        yield 'erreur 5xx' => [self::awsFailure(503)];
        yield 'throttling en 429' => [self::awsFailure(429)];
        yield 'throttling en 400' => [self::awsFailure(400, 'RequestThrottled')];
    }

    /**
     * **Le piege du generateur.** `AmazonSqsReceiver::get()` ne fait rien a l'appel : l'erreur
     * survient quand on itere. Un `try` autour du seul appel la laisserait passer, et le worker
     * mourrait comme avant. Ce test le reproduit a l'identique.
     */
    public function testItCatchesTheFailureWhileTheReceiverIsIterated(): void
    {
        $failure = new TransportException('Could not contact remote server.', 0, new NetworkException('Could not contact remote server.'));
        $this->inner->method('get')->willReturnCallback(fn (): Generator => $this->throwing($failure));

        self::assertSame([], $this->transport->get());
        self::assertSame(1, $this->secondsWaited());
    }

    /**
     * L'attente double a chaque echec, et plafonne a 30 s : le retour du bus est vu en 30 s au
     * plus, sans marteler SQS pendant une longue panne.
     */
    public function testItWaitsLongerAtEachFailureUpToThirtySeconds(): void
    {
        $this->inner->method('get')->willThrowException(
            new TransportException('down', 0, new NetworkException('Could not contact remote server.')),
        );

        $waits = [];
        for ($attempt = 0; $attempt < 7; ++$attempt) {
            $before = $this->secondsWaited();
            $this->transport->get();
            $waits[] = $this->secondsWaited() - $before;
        }

        self::assertSame([1, 2, 4, 8, 16, 30, 30], $waits);
    }

    /**
     * Au retour du bus, les messages sont rendus, le retour est journalise, et l'attente repart de
     * 1 s : une panne suivante ne commence pas au plafond.
     */
    public function testItResumesAndStartsOverOnceTheBusIsBack(): void
    {
        $down = new TransportException('down', 0, new NetworkException('Could not contact remote server.'));
        $envelope = new Envelope(new stdClass());
        $this->inner->method('get')->willReturnOnConsecutiveCalls(
            $this->throwing($down),
            $this->throwing($down),
            [$envelope],
            $this->throwing($down),
        );

        $this->transport->get();
        $this->transport->get();
        self::assertSame([$envelope], $this->transport->get());
        self::assertTrue($this->logs->hasRecordThatContains('Integration bus reachable again', Level::Info));

        $before = $this->secondsWaited();
        $this->transport->get();
        self::assertSame(1, $this->secondsWaited() - $before);
    }

    /**
     * Le temoin des precedents : une erreur qui n'est **pas** une indisponibilite remonte, sans
     * attendre. Une file inexistante ne reviendra pas en attendant : le processus doit mourir
     * bruyamment, pas attendre en silence.
     */
    public function testItLetsAnyOtherTransportFailureThrough(): void
    {
        $failure = new TransportException('no such queue', 0, self::awsFailure(400, 'AWS.SimpleQueueService.NonExistentQueue'));
        $this->inner->method('get')->willThrowException($failure);

        try {
            $this->transport->get();
            self::fail('Une erreur qui n est pas une indisponibilite doit remonter.');
        } catch (TransportException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(0, $this->secondsWaited());
    }

    /**
     * Une erreur de decodage n'est pas une panne du bus : elle remonte. Le serialiseur ne la leve
     * plus que pour un renvoi de retry illisible, ecrit par ce service lui-meme — un bug d'ici, que
     * l'attente masquerait.
     */
    public function testItLetsAnUndecodableBodyThrough(): void
    {
        $this->inner->method('get')->willThrowException(new MessageDecodingFailedException('{"id": "casse'));

        $this->expectException(MessageDecodingFailedException::class);

        $this->transport->get();
    }

    /**
     * Messenger active chaque capacite par `instanceof` sur le transport : en oublier une la ferait
     * disparaitre sans erreur (`messenger:stats` cesserait de lister la file).
     */
    public function testItKeepsEveryCapabilityOfTheDecoratedTransport(): void
    {
        $envelope = new Envelope(new stdClass());
        $inner = $this->createMock(AmazonSqsTransport::class);
        $transport = new BusOutageTolerantTransport($inner, $this->clock, $this->logger);

        $inner->expects($this->once())->method('ack')->with($envelope);
        $inner->expects($this->once())->method('reject')->with($envelope);
        $inner->expects($this->once())->method('send')->with($envelope)->willReturn($envelope);
        $inner->expects($this->once())->method('keepalive')->with($envelope, 30);
        $inner->expects($this->once())->method('setup');
        $inner->expects($this->once())->method('close');
        $inner->expects($this->once())->method('getMessageCount')->willReturn(3);
        $inner->expects($this->once())->method('reset');

        $transport->ack($envelope);
        $transport->reject($envelope);
        self::assertSame($envelope, $transport->send($envelope));
        $transport->keepalive($envelope, 30);
        $transport->setup();
        $transport->close();
        self::assertSame(3, $transport->getMessageCount());
        $transport->reset();
    }

    private function secondsWaited(): int
    {
        return $this->clock->now()->getTimestamp() - (new MockClock('2026-10-23 00:00:00'))->now()->getTimestamp();
    }

    /**
     * L'exception qu'AsyncAws leve pour une reponse en erreur : 5xx en `ServerException`, 4xx en
     * `ClientException`, avec le code AWS du corps.
     */
    private static function awsFailure(int $status, ?string $awsCode = null): Throwable
    {
        $result = ResultMockFactory::createFailing(Result::class, $status, 'failure', null === $awsCode ? [] : ['__type' => $awsCode]);

        try {
            $result->resolve();
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('awsFailure: the failing result did not fail');
    }

    /**
     * Un generateur qui echoue **a l'iteration**, comme `AmazonSqsReceiver::get()` : son corps ne
     * s'execute qu'au premier tour. Pas de `throw` suivi d'un `yield` mort, que Rector retirerait
     * (`RemoveUnreachableStatementRector`), faisant echouer la fonction des l'appel et le test
     * du generateur sans rien prouver : `yield from []` en fait un generateur, et le `throw` qui
     * suit reste atteignable.
     *
     * @return Generator<int, Envelope>
     */
    private function throwing(Throwable $failure): Generator
    {
        yield from [];

        throw $failure;
    }
}
