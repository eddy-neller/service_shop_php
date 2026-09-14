<?php

declare(strict_types=1);

namespace App\Application\Customer\ReadModel;

use App\Domain\Customer\Model\Customer;

final readonly class CurrentCustomerItem
{
    public function __construct(
        public string $id,
    ) {
    }

    public static function fromCustomer(Customer $customer): self
    {
        return new self(
            id: $customer->getId()->toString(),
        );
    }
}
