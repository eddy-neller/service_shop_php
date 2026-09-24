<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\DisableCustomerForAccount;

use App\Application\Shared\CQRS\Command\CommandInterface;

/**
 * Desactive le client rattache a un compte utilisateur supprime dans `service_identity`.
 *
 * Clee sur le compte, comme le provisionnement : l'emetteur ne connait pas nos identifiants.
 * Jamais une suppression — un client porte des commandes, et son historique doit survivre au
 * compte qui l'a cree.
 */
final readonly class DisableCustomerForAccountCommand implements CommandInterface
{
    public function __construct(
        public string $userAccountId,
    ) {
    }
}
