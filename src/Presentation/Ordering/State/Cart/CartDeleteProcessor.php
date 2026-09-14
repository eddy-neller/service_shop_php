<?php

declare(strict_types=1);

namespace App\Presentation\Ordering\State\Cart;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Ordering\UseCase\Command\ClearCart\ClearCartCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Shared\State\CurrentCustomerResolver;

final readonly class CartDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentCustomerResolver $customerResolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->commandBus->dispatch(new ClearCartCommand($this->customerResolver->resolve()));
    }
}
