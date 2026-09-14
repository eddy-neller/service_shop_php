<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\CreateAddress;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class CreateAddressCommand implements CommandInterface
{
    public function __construct(
        public string $ownerId,
        public string $label,
        public string $firstname,
        public string $lastname,
        public ?string $company,
        public string $street,
        public string $zipCode,
        public string $city,
        public string $country,
        public string $phone,
    ) {
    }
}
