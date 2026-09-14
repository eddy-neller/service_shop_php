<?php

declare(strict_types=1);

namespace App\Application\Customer\ReadModel;

use App\Domain\Customer\Model\Customer;
use DateTimeImmutable;

final readonly class CustomerItem
{
    /**
     * @param list<AddressItem> $addresses
     */
    public function __construct(
        public string $id,
        public ?string $userAccountId,
        public int $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $addresses,
    ) {
    }

    /**
     * @param list<AddressItem> $addresses
     */
    public static function fromCustomer(Customer $customer, array $addresses = []): self
    {
        return new self(
            id: $customer->getId()->toString(),
            userAccountId: $customer->getUserAccountId()?->toString(),
            status: $customer->getStatus()->toInt(),
            createdAt: $customer->getCreatedAt(),
            updatedAt: $customer->getUpdatedAt(),
            addresses: $addresses,
        );
    }
}
