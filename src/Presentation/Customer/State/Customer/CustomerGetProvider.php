<?php

declare(strict_types=1);

namespace App\Presentation\Customer\State\Customer;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Customer\UseCase\Query\DisplayCustomer\DisplayCustomerQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Customer\ApiResource\CustomerResource;
use App\Presentation\Customer\Presenter\CustomerResourcePresenter;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class CustomerGetProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CustomerResourcePresenter $customerResourcePresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        $customerId = $uriVariables['id'] ?? null;

        if (!is_string($customerId) || '' === $customerId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $output = $this->queryBus->dispatch(new DisplayCustomerQuery($customerId));

        return $this->customerResourcePresenter->toResource($output);
    }
}
