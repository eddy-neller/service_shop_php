<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayListCustomer;

use App\Application\Shared\CQRS\Query\QueryInterface;

final readonly class DisplayListCustomerQuery implements QueryInterface
{
    /**
     * Champs sur lesquels la liste peut être triée ; les autres sont ignorés.
     *
     * `username` n'en fait pas partie : ce tri s'appuyait sur une jointure DQL vers la table des
     * comptes utilisateurs, qui vit desormais dans un autre service et une autre base. Le read model
     * n'expose de toute facon pas ce champ — seul le tri est perdu.
     */
    public const array SORT_FIELDS = ['status', 'createdAt'];

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
