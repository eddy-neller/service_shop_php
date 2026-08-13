<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\CQRS\Middleware;

use App\Infrastructure\Symfony\Messenger\CQRS\Middleware\UnwrapHandlerFailedExceptionMiddleware;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class UnwrapHandlerFailedExceptionMiddlewareTest extends TestCase
{
    public function testHandleRethrowsTheSingleOriginalException(): void
    {
        $envelope = new Envelope(new stdClass());
        $originalException = new RuntimeException('Domain failure');
        $wrappedException = new HandlerFailedException($envelope, ['handler' => $originalException]);
        $stack = $this->stackThrowing($envelope, $wrappedException);

        try {
            (new UnwrapHandlerFailedExceptionMiddleware())->handle($envelope, $stack);
            self::fail('An exception should have been thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($originalException, $exception);
        }
    }

    public function testHandleKeepsWrapperWhenSeveralHandlersFailed(): void
    {
        $envelope = new Envelope(new stdClass());
        $wrappedException = new HandlerFailedException($envelope, [
            'first_handler' => new RuntimeException('First failure'),
            'second_handler' => new RuntimeException('Second failure'),
        ]);
        $stack = $this->stackThrowing($envelope, $wrappedException);

        try {
            (new UnwrapHandlerFailedExceptionMiddleware())->handle($envelope, $stack);
            self::fail('An exception should have been thrown.');
        } catch (HandlerFailedException $exception) {
            self::assertSame($wrappedException, $exception);
        }
    }

    private function stackThrowing(Envelope $envelope, HandlerFailedException $exception): StackInterface
    {
        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects($this->once())
            ->method('handle')
            ->with($envelope)
            ->willThrowException($exception);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())
            ->method('next')
            ->willReturn($next);

        return $stack;
    }
}
