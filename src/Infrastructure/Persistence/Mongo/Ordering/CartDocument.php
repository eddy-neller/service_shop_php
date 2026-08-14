<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Ordering;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as MongoDB;

/**
 * Representation persistee d'un panier.
 *
 * `cart_customer_uniq` porte a lui seul la garantie « un panier par client ». Le monolithe
 * la tenait par un verrou pessimiste pris sur la ligne du client avant toute creation ;
 * MongoDB n'ayant pas de `SELECT … FOR UPDATE`, c'est l'index qui arbitre : deux requetes
 * concurrentes peuvent toutes deux ne rien trouver et construire un panier, le perdant voit
 * sa transaction entiere annulee.
 *
 * `#[MongoDB\Version]` couvre l'autre course, celle sur un panier **existant** : sans lui,
 * deux ajouts simultanes du meme produit se fusionneraient chacun de leur cote et le dernier
 * ecraserait l'autre, sans erreur.
 */
#[MongoDB\Document(collection: 'cart')]
#[MongoDB\UniqueIndex(keys: ['customerId' => 'asc'], name: 'cart_customer_uniq')]
class CartDocument
{
    #[MongoDB\Id(type: 'string', strategy: 'NONE')]
    public string $id;

    #[MongoDB\Field(type: 'string')]
    public string $customerId;

    /** @var Collection<int, CartLineEmbeddedDocument> */
    #[MongoDB\EmbedMany(targetDocument: CartLineEmbeddedDocument::class)]
    public Collection $lines;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $createdAt;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $updatedAt;

    #[MongoDB\Version]
    #[MongoDB\Field(type: 'int')]
    public int $version = 1;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }
}
