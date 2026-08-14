<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\DisableCustomer;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class DisableCustomerCommand implements CommandInterface
{
    public function __construct(
        public string $customerId,
    ) {
    }
}
