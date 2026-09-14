<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\SetDefaultAddress;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class SetDefaultAddressCommand implements CommandInterface
{
    public function __construct(
        public string $addressId,
        public string $ownerId,
    ) {
    }
}
