<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayListAddress;

use App\Application\Shared\CQRS\Query\QueryInterface;

final readonly class DisplayListAddressQuery implements QueryInterface
{
    /** Champs sur lesquels la liste peut être triée ; les autres sont ignorés. Le tri est appliqué par `DisplayListAddressQueryHandler`. */
    public const array SORT_FIELDS = ['name', 'city', 'country', 'createdAt'];

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
