<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\CQRS\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class UnwrapHandlerFailedExceptionMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $exception) {
            $wrappedExceptions = $exception->getWrappedExceptions();

            if (1 === count($wrappedExceptions)) {
                throw array_values($wrappedExceptions)[0];
            }

            throw $exception;
        }
    }
}
