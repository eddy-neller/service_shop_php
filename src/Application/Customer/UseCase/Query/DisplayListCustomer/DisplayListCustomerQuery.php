<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayListCustomer;

use App\Application\Shared\CQRS\Query\QueryInterface;

final readonly class DisplayListCustomerQuery implements QueryInterface
{
    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $orderBy
     */
    public function __construct(
        public ?string $page = null,
        public ?string $itemsPerPage = null,
        public array $filters = [],
        public array $orderBy = [],
    ) {
    }
}
