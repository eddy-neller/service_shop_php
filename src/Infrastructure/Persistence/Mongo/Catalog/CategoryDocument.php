<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Catalog;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Attribute as MongoDB;

/**
 * Representation persistee d'une categorie.
 *
 * L'arbre etait un *nested set* maintenu par Gedmo cote monolithe (colonnes lft/rgt/lvl).
 * Ici il est reduit a `parentId` + `level` denormalise : MongoDB n'a pas d'extension
 * equivalente, et un arbre de catalogue est assez petit pour que le maintien explicite
 * du niveau dans `MongoCategoryRepository` soit plus lisible qu'une renumerotation
 * d'intervalles.
 *
 * `hasChildren` n'est volontairement pas un champ : il se deduit de l'existence d'un
 * document portant `parentId = _id`. Le stocker creerait un second etat a maintenir.
 */
#[MongoDB\Document(collection: 'category')]
#[MongoDB\UniqueIndex(keys: ['title' => 'asc'], name: 'category_title_uniq')]
#[MongoDB\UniqueIndex(keys: ['slug' => 'asc'], name: 'category_slug_uniq')]
#[MongoDB\Index(keys: ['parentId' => 'asc'], name: 'category_parent_idx')]
#[MongoDB\Index(keys: ['level' => 'asc'], name: 'category_level_idx')]
class CategoryDocument
{
    #[MongoDB\Id(type: 'string', strategy: 'NONE')]
    public string $id;

    #[MongoDB\Field(type: 'string')]
    public string $title;

    #[MongoDB\Field(type: 'string', nullable: true)]
    public ?string $description = null;

    #[MongoDB\Field(type: 'string')]
    public string $slug;

    #[MongoDB\Field(type: 'string', nullable: true)]
    public ?string $parentId = null;

    #[MongoDB\Field(type: 'int')]
    public int $nbProduct = 0;

    #[MongoDB\Field(type: 'int')]
    public int $level = 0;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $createdAt;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $updatedAt;
}
