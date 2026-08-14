<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\CreateCustomer;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class CreateCustomerCommand implements CommandInterface
{
    public function __construct(
        public string $userAccountId,
    ) {
    }
}
