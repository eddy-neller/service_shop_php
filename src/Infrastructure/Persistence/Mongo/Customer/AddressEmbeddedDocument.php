<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Customer;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Attribute as MongoDB;

/**
 * Adresse imbriquee dans son client. Le suffixe `…Document` n'est pas cosmetique : il la fait
 * tomber sous le glob d'exclusion `Persistence/Mongo/**\/*Document.php` de `services.yaml`,
 * qui evite de l'enregistrer comme service.
 *
 * `ownerId` est conserve alors qu'il est deduit de l'imbrication : le domaine le porte pour
 * `belongsTo()`, et le read model l'expose. Le mapper le reecrit a chaque ecriture, donc il
 * ne peut pas deriver.
 */
#[MongoDB\EmbeddedDocument]
class AddressEmbeddedDocument
{
    #[MongoDB\Field(type: 'string')]
    public string $id;

    #[MongoDB\Field(type: 'string')]
    public string $ownerId;

    #[MongoDB\Field(type: 'string')]
    public string $label;

    #[MongoDB\Field(type: 'string')]
    public string $firstname;

    #[MongoDB\Field(type: 'string')]
    public string $lastname;

    #[MongoDB\Field(type: 'string', nullable: true)]
    public ?string $company = null;

    #[MongoDB\Field(type: 'string')]
    public string $street;

    #[MongoDB\Field(type: 'string')]
    public string $zipCode;

    #[MongoDB\Field(type: 'string')]
    public string $city;

    #[MongoDB\Field(type: 'string')]
    public string $country;

    #[MongoDB\Field(type: 'string')]
    public string $phone;

    #[MongoDB\Field(type: 'bool')]
    public bool $isDefault = false;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $createdAt;

    #[MongoDB\Field(type: 'date_immutable')]
    public DateTimeImmutable $updatedAt;
}
