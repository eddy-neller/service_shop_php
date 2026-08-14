<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Customer;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as MongoDB;

/**
 * Representation persistee d'un client, **adresses comprises**.
 *
 * PostgreSQL les tenait dans une table a part, avec un verrou pessimiste pour le plafond de
 * cinq et un index unique partiel pour l'unicite du defaut. MongoDB n'a ni l'un ni l'autre :
 * imbriquees, les deux regles se verifient en memoire dans l'agregat et partent dans une
 * seule ecriture de document.
 *
 * `#[MongoDB\Version]` n'est pas decoratif. L'imbrication transforme « deux ecritures
 * concurrentes depassent le plafond » en « la derniere ecrase la premiere, sans erreur » :
 * chacune produit un document valide d'au plus cinq adresses. Le verrou optimiste rejette
 * le perdant, que `MongoTransactional` traduit en `ConcurrentModificationException` (409).
 * Le retirer ne ferait echouer aucun test d'unite — seuls les tests d'integration dedies
 * s'en apercevraient.
 */
#[MongoDB\Document(collection: 'customer')]
#[MongoDB\UniqueIndex(
    keys: ['userAccountId' => 'asc'],
    name: 'customer_user_account_uniq',
    partialFilterExpression: ['userAccountId' => ['$type' => 'string']],
)]
#[MongoDB\Index(keys: ['status' => 'asc'], name: 'customer_status_idx')]
#[MongoDB\Index(keys: ['createdAt' => 'asc'], name: 'customer_created_idx')]
class CustomerDocument
{
    #[MongoDB\Id(type: 'string', strategy: 'NONE')]
    public string $id;

    /**
     * Son index unique est **partiel**, et non `sparse` : un administrateur peut creer un
     * client sans compte, et il en faut plusieurs.
     *
     * `sparse: true` serait le reflexe, et il ne marche pas : un index sparse ignore les
     * documents ou le champ est **absent**, alors que l'ODM ecrit explicitement
     * `userAccountId: null`. Le document est donc indexe, et le second client sans compte
     * est rejete sur une cle `null` dupliquee. Le filtre `$type: 'string'` n'indexe que les
     * clients reellement rattaches a un compte.
     */
    #[MongoDB\Field(type: 'string', nullable: true)]
    public ?string $userAccountId = null;

    #[MongoDB\Field(type: 'int')]
    public int $status = 1;

    /** @var Collection<int, AddressEmbeddedDocument> */
    #[MongoDB\EmbedMany(targetDocument: AddressEmbeddedDocument::class)]
    public Collection $addresses;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $createdAt;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $updatedAt;

    #[MongoDB\Version]
    #[MongoDB\Field(type: 'int')]
    public int $version = 1;

    public function __construct()
    {
        $this->addresses = new ArrayCollection();
    }
}
