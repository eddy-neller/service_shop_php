<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\CQRS\Middleware;

use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class CommandLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $commandClass = $envelope->getMessage()::class;
        $startTime = microtime(true);

        $this->logger->info('Dispatching command', [
            'command' => $commandClass,
        ]);

        try {
            $handledEnvelope = $stack->next()->handle($envelope, $stack);

            $this->logger->info('Command handled successfully', [
                'command' => $commandClass,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return $handledEnvelope;
        } catch (Exception $exception) {
            $this->logger->error('Command failed', [
                'command' => $commandClass,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
