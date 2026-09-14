<?php

declare(strict_types=1);

namespace App\Presentation\Customer\State\Customer;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Customer\UseCase\Command\DisableCustomer\DisableCustomerCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Customer\ApiResource\CustomerResource;
use App\Presentation\Customer\Dto\CustomerPatchInput;
use App\Presentation\Customer\Presenter\CustomerResourcePresenter;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class CustomerPatchProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CustomerResourcePresenter $customerResourcePresenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        if (!$data instanceof CustomerPatchInput) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $customerId = $uriVariables['id'] ?? null;

        if (!is_string($customerId) || '' === $customerId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $output = $this->commandBus->dispatch(new DisableCustomerCommand($customerId));

        return $this->customerResourcePresenter->toSummaryResource($output);
    }
}
