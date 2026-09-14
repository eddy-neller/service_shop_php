<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\CQRS;

use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Application\Shared\CQRS\Query\QueryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerQueryBus implements QueryBusInterface
{
    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $bus,
        private HandledResultExtractor $resultExtractor,
    ) {
    }

    public function dispatch(QueryInterface $query): mixed
    {
        return $this->resultExtractor->extract($this->bus->dispatch($query));
    }
}
