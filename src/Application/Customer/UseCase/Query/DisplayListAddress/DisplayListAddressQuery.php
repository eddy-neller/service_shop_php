<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayListAddress;

use App\Application\Shared\CQRS\Query\QueryInterface;

final readonly class DisplayListAddressQuery implements QueryInterface
{
    /**
     * @param array<string, mixed> $orderBy
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public string $ownerId,
        public ?string $page,
        public ?string $itemsPerPage,
        public array $orderBy,
        public array $filters,
    ) {
    }
}
