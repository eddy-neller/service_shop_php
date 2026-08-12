<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Catalog;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * Representation persistee d'un produit.
 *
 * `categoryId` est une **chaine**, pas une `#[MongoDB\ReferenceOne]` : on ne reintroduit
 * pas le graphe d'objets qu'on vient de quitter. Le domaine ne connait qu'un `CategoryId`,
 * la persistance ne doit pas en savoir plus.
 *
 * `price` est stocke en centimes, comme le faisait la colonne SQL — `Money` est une valeur
 * entiere, la representer en flottant reintroduirait les erreurs d'arrondi qu'il evite.
 */
#[MongoDB\Document(collection: 'product')]
#[MongoDB\UniqueIndex(keys: ['title' => 'asc'], name: 'product_title_uniq')]
#[MongoDB\UniqueIndex(keys: ['slug' => 'asc'], name: 'product_slug_uniq')]
#[MongoDB\Index(keys: ['categoryId' => 'asc'], name: 'product_category_idx')]
#[MongoDB\Index(keys: ['createdAt' => 'desc'], name: 'product_created_at_idx')]
class ProductDocument
{
    #[MongoDB\Id(type: 'string', strategy: 'NONE')]
    public string $id;

    #[MongoDB\Field(type: 'string')]
    public string $title;

    #[MongoDB\Field(type: 'string')]
    public string $subtitle;

    #[MongoDB\Field(type: 'string')]
    public string $description;

    #[MongoDB\Field(type: 'int')]
    public int $priceAmount;

    #[MongoDB\Field(type: 'string')]
    public string $priceCurrency;

    #[MongoDB\Field(type: 'string')]
    public string $slug;

    #[MongoDB\Field(type: 'string')]
    public string $categoryId;

    #[MongoDB\Field(type: 'string', nullable: true)]
    public ?string $imageName = null;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $createdAt;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $updatedAt;
}
