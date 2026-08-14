<?php

declare(strict_types=1);

namespace App\Presentation\Customer\State\Address;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Customer\UseCase\Command\SetDefaultAddress\SetDefaultAddressCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Customer\ApiResource\AddressResource;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class AddressDefaultProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentCustomerResolver $customerResolver,
        private AddressResourcePresenter $presenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AddressResource
    {
        $addressId = $uriVariables['id'] ?? null;

        if (!is_string($addressId) || '' === $addressId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $output = $this->commandBus->dispatch(new SetDefaultAddressCommand(
            addressId: $addressId,
            ownerId: $this->customerResolver->resolve(),
        ));

        return $this->presenter->toResource($output);
    }
}
