<?php

declare(strict_types=1);

namespace App\Presentation\Customer\State\Customer;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Customer\UseCase\Command\CreateCustomer\CreateCustomerCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Customer\ApiResource\CustomerResource;
use App\Presentation\Customer\Dto\CustomerPostInput;
use App\Presentation\Customer\Presenter\CustomerResourcePresenter;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class CustomerPostProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CustomerResourcePresenter $customerResourcePresenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        if (!$data instanceof CustomerPostInput) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $userAccountId = $data->userAccountId;

        $output = $this->commandBus->dispatch(new CreateCustomerCommand($userAccountId));

        return $this->customerResourcePresenter->toSummaryResource($output);
    }
}
