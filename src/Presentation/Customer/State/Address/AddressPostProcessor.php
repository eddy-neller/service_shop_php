<?php

declare(strict_types=1);

namespace App\Presentation\Customer\State\Address;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Customer\UseCase\Command\CreateAddress\CreateAddressCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Customer\ApiResource\AddressResource;
use App\Presentation\Customer\Dto\AddressPostInput;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class AddressPostProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentCustomerResolver $customerResolver,
        private AddressResourcePresenter $presenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AddressResource
    {
        if (!$data instanceof AddressPostInput) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $command = new CreateAddressCommand(
            ownerId: $this->customerResolver->resolve(),
            label: $data->name,
            firstname: $data->firstname,
            lastname: $data->lastname,
            company: $data->company,
            street: $data->address,
            zipCode: $data->zip,
            city: $data->city,
            country: $data->country,
            phone: $data->phone,
        );

        $output = $this->commandBus->dispatch($command);

        return $this->presenter->toResource($output);
    }
}
