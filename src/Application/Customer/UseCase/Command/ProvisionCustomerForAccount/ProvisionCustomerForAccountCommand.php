<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\ProvisionCustomerForAccount;

use App\Application\Shared\CQRS\Command\CommandInterface;

/**
 * Provisionne le client rattache a un compte utilisateur de `service_identity`.
 *
 * Clee sur le compte et non sur le client : l'emetteur ne connait pas nos identifiants, et ne
 * doit pas avoir a les connaitre. C'est ce qui permet de rejouer le message sans etat partage.
 */
final readonly class ProvisionCustomerForAccountCommand implements CommandInterface
{
    public function __construct(
        public string $userAccountId,
    ) {
    }
}
