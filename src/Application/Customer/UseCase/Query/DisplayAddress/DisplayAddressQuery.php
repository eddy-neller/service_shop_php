<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayAddress;

use App\Application\Shared\CQRS\Query\QueryInterface;

final readonly class DisplayAddressQuery implements QueryInterface
{
    public function __construct(
        public string $addressId,
        public string $ownerId,
    ) {
    }
}
