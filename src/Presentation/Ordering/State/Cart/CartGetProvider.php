<?php

declare(strict_types=1);

namespace App\Presentation\Ordering\State\Cart;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Ordering\UseCase\Query\DisplayMyCart\DisplayMyCartQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Ordering\ApiResource\CartResource;
use App\Presentation\Ordering\Presenter\CartResourcePresenter;
use App\Presentation\Shared\State\CurrentCustomerResolver;

final readonly class CartGetProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentCustomerResolver $customerResolver,
        private CartResourcePresenter $presenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CartResource
    {
        $output = $this->queryBus->dispatch(new DisplayMyCartQuery($this->customerResolver->resolve()));

        return $this->presenter->toResource($output);
    }
}
