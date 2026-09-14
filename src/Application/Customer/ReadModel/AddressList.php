<?php

declare(strict_types=1);

namespace App\Application\Customer\ReadModel;

final readonly class AddressList
{
    /**
     * @param list<AddressItem> $items
     */
    public function __construct(
        public array $items,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
