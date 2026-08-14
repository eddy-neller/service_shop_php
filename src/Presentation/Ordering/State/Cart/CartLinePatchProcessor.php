<?php

declare(strict_types=1);

namespace App\Presentation\Ordering\State\Cart;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Ordering\UseCase\Command\UpdateCartLine\UpdateCartLineCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Ordering\ApiResource\CartResource;
use App\Presentation\Ordering\Dto\Cart\CartLinePatchInput;
use App\Presentation\Ordering\Presenter\CartResourcePresenter;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class CartLinePatchProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentCustomerResolver $customerResolver,
        private CartResourcePresenter $presenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CartResource
    {
        $productId = $uriVariables['productId'] ?? null;
        if (!$data instanceof CartLinePatchInput || !is_string($productId) || '' === $productId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $output = $this->commandBus->dispatch(new UpdateCartLineCommand(
            $this->customerResolver->resolve(),
            $productId,
            $data->quantity,
        ));

        return $this->presenter->toResource($output);
    }
}
