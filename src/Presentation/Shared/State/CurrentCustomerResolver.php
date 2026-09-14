<?php

declare(strict_types=1);

namespace App\Presentation\Shared\State;

use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Shared\Security\CustomerMeSecurityTrait;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Traduit le `sub` du jeton en `customerId`, avant chaque operation `/me`.
 *
 * Le client n'existant pas encore (relais de provisionnement pas encore passe), la query
 * leve `CustomerNotFoundException` -> 404. Cette fenetre est assumee : voir `AGENTS.md`.
 */
final readonly class CurrentCustomerResolver
{
    use CustomerMeSecurityTrait;

    public function __construct(
        private QueryBusInterface $queryBus,
        private Security $security,
    ) {
    }

    public function resolve(): string
    {
        $user = $this->getCurrentUserOrThrow();

        $output = $this->queryBus->dispatch(
            new DisplayMyCustomerQuery($this->getUserIdFromAuthenticatedUser($user)),
        );

        return $output->id;
    }

    protected function getSecurity(): Security
    {
        return $this->security;
    }
}
