<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\CQRS\Middleware;

use App\Infrastructure\Symfony\Messenger\CQRS\Middleware\CommandLoggingMiddleware;
use App\Infrastructure\Symfony\Messenger\CQRS\Middleware\QueryLoggingMiddleware;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class LoggingMiddlewareTest extends TestCase
{
    public function testCommandMiddlewareLogsSuccessfulHandling(): void
    {
        $logger = new InMemoryLogger();
        $envelope = new Envelope(new stdClass());
        $handledEnvelope = new Envelope($envelope->getMessage());
        $stack = $this->stackReturning($handledEnvelope);

        $result = (new CommandLoggingMiddleware($logger))->handle($envelope, $stack);

        self::assertSame($handledEnvelope, $result);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('Dispatching command', $logger->records[0]['message']);
        self::assertSame(stdClass::class, $logger->records[0]['context']['command']);
        self::assertSame('Command handled successfully', $logger->records[1]['message']);
        self::assertArrayHasKey('duration_ms', $logger->records[1]['context']);
    }

    public function testQueryMiddlewareLogsAndRethrowsFailure(): void
    {
        $logger = new InMemoryLogger();
        $envelope = new Envelope(new stdClass());
        $exception = new RuntimeException('Query failure');
        $stack = $this->stackThrowing($exception);

        try {
            (new QueryLoggingMiddleware($logger))->handle($envelope, $stack);
            self::fail('An exception should have been thrown.');
        } catch (RuntimeException $thrownException) {
            self::assertSame($exception, $thrownException);
        }

        self::assertSame('Dispatching query', $logger->records[0]['message']);
        self::assertSame('error', $logger->records[1]['level']);
        self::assertSame('Query failed', $logger->records[1]['message']);
        self::assertSame(RuntimeException::class, $logger->records[1]['context']['exception']);
        self::assertSame('Query failure', $logger->records[1]['context']['message']);
    }

    private function stackReturning(Envelope $handledEnvelope): StackInterface
    {
        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects($this->once())->method('handle')->willReturn($handledEnvelope);
        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($next);

        return $stack;
    }

    private function stackThrowing(RuntimeException $exception): StackInterface
    {
        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects($this->once())->method('handle')->willThrowException($exception);
        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($next);

        return $stack;
    }
}
