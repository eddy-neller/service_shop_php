<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\CQRS;

use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Command\CommandInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerCommandBus implements CommandBusInterface
{
    public function __construct(
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $bus,
        private HandledResultExtractor $resultExtractor,
    ) {
    }

    public function dispatch(CommandInterface $command): mixed
    {
        return $this->resultExtractor->extract($this->bus->dispatch($command));
    }
}
