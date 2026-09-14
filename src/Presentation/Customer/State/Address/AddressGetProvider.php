<?php

declare(strict_types=1);

namespace App\Presentation\Customer\State\Address;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Customer\UseCase\Query\DisplayAddress\DisplayAddressQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Customer\ApiResource\AddressResource;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class AddressGetProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentCustomerResolver $customerResolver,
        private AddressResourcePresenter $presenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AddressResource
    {
        $addressId = $uriVariables['id'] ?? null;
        if (!is_string($addressId) || '' === $addressId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $output = $this->queryBus->dispatch(new DisplayAddressQuery(
            addressId: $addressId,
            ownerId: $this->customerResolver->resolve(),
        ));

        return $this->presenter->toResource($output);
    }
}
