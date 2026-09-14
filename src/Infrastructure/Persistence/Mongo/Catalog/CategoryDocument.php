<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Catalog;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Attribute as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Tree\Document\MongoDB\Repository\MaterializedPathRepository;

/**
 * Representation persistee d'une categorie.
 *
 * `hasChildren` n'est volontairement pas un champ : il se deduit de l'existence d'un
 * document referencant cette categorie comme parent. Le stocker creerait un second etat
 * a maintenir.
 */
#[Gedmo\Tree(type: 'materializedPath')]
#[MongoDB\Document(collection: 'category', repositoryClass: MaterializedPathRepository::class)]
#[MongoDB\UniqueIndex(keys: ['title' => 'asc'], name: 'category_title_uniq')]
#[MongoDB\UniqueIndex(keys: ['slug' => 'asc'], name: 'category_slug_uniq')]
#[MongoDB\Index(keys: ['parent' => 'asc'], name: 'category_parent_idx')]
#[MongoDB\Index(keys: ['path' => 'asc'], name: 'category_path_idx')]
#[MongoDB\Index(keys: ['level' => 'asc'], name: 'category_level_idx')]
class CategoryDocument
{
    #[Gedmo\TreePathSource]
    #[MongoDB\Id(type: 'string', strategy: 'NONE')]
    public string $id;

    #[MongoDB\Field(type: 'string')]
    public string $title;

    #[MongoDB\Field(type: 'string', nullable: true)]
    public ?string $description = null;

    #[MongoDB\Field(type: 'string')]
    public string $slug;

    #[Gedmo\TreeParent]
    #[MongoDB\ReferenceOne(storeAs: 'id', targetDocument: self::class)]
    public ?self $parent = null;

    #[Gedmo\TreePath(separator: '/', appendId: false)]
    #[MongoDB\Field(type: 'string')]
    public string $path = '';

    #[MongoDB\Field(type: 'int')]
    public int $nbProduct = 0;

    #[Gedmo\TreeLevel]
    #[MongoDB\Field(type: 'int')]
    public int $level = 0;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $createdAt;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $updatedAt;
}
