<?php

declare(strict_types=1);

namespace App\Domain\Customer\Event;

use App\Domain\Customer\ValueObject\AddressId;

/**
 * Une adresse n'est pas une racine d'agregat ici : elle vit dans le document du client.
 * Ses evenements restent donc des faits du contexte Customer, et leur `aggregateId()`
 * est celui du **client**, pas celui de l'adresse — c'est le client qui a ete modifie.
 *
 * `getAddressId()` reste expose pour que le journal designe la ligne concernee.
 */
interface AddressDomainEventInterface extends CustomerDomainEventInterface
{
    public function getAddressId(): AddressId;
}
