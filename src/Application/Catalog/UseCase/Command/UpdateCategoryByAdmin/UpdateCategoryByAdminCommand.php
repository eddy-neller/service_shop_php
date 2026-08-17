<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\UpdateCategoryByAdmin;

use App\Application\Shared\CQRS\Command\CommandInterface;

final readonly class UpdateCategoryByAdminCommand implements CommandInterface
{
    /**
     * `parentId` porte la valeur du parent tandis que `parentProvided` conserve la
     * presence du champ dans un PATCH : `null` peut vouloir dire « champ omis »
     * (ne pas deplacer la categorie) ou `"parent": null` (la remettre a la racine).
     */
    public function __construct(
        public string $categoryId,
        public ?string $title,
        public ?string $description,
        public ?string $parentId,
        public bool $parentProvided = false,
    ) {
    }
}
