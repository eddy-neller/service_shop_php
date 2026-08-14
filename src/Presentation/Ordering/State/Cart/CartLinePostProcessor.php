<?php

declare(strict_types=1);

namespace App\Presentation\Ordering\State\Cart;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Ordering\UseCase\Command\AddToCart\AddToCartCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Ordering\ApiResource\CartResource;
use App\Presentation\Ordering\Dto\Cart\CartLinePostInput;
use App\Presentation\Ordering\Presenter\CartResourcePresenter;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class CartLinePostProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentCustomerResolver $customerResolver,
        private CartResourcePresenter $presenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CartResource
    {
        if (!$data instanceof CartLinePostInput) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $output = $this->commandBus->dispatch(new AddToCartCommand(
            $this->customerResolver->resolve(),
            $data->productId,
            $data->quantity,
        ));

        return $this->presenter->toResource($output);
    }
}
