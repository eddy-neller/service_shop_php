<?php

declare(strict_types=1);

namespace App\Domain\Customer\Event;

use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Customer\ValueObject\UserAccountId;
use App\Domain\SharedKernel\Event\DomainEventInterface;

/**
 * Marqueur du contexte Customer.
 *
 * Il porte les deux identites sous lesquelles un client est lu, parce que c'est
 * `DomainEventCacheTags` qui les consomme : `customer-{id}` pour les lectures par
 * identifiant de client, `customer-of-user-{id}` pour la traduction JWT -> client
 * faite a chaque requete `/me`. Typer sur ce marqueur unique evite d'enumerer les
 * evenements un a un dans le `match` des tags.
 *
 * `getUserAccountId()` est nullable : le domaine autorise un client sans compte
 * (creation manuelle par un administrateur), auquel cas il n'y a pas de tag `/me`
 * a purger.
 */
interface CustomerDomainEventInterface extends DomainEventInterface
{
    public function getCustomerId(): CustomerId;

    public function getUserAccountId(): ?UserAccountId;
}
