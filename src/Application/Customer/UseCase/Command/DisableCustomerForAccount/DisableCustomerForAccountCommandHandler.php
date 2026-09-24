<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\DisableCustomerForAccount;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\UserAccountId;

/**
 * Reaction a la suppression d'un compte utilisateur, declenchee par un message du bus.
 *
 * **Commutatif avec `ProvisionCustomerForAccount`, et idempotent.** Le bus livre
 * au-moins-une-fois et sans ordre garanti, donc ce message peut arriver **avant** celui qui
 * annonce la creation du compte — ou deux fois.
 *
 * Trois situations, une seule issue :
 * - client actif : il est desactive, un `CustomerDisabledEvent` est publie ;
 * - client deja desactive : rien, pas meme une ecriture. Republier l'evenement reinvaliderait
 *   des caches pour rien, et le verrou optimiste du document n'a pas a etre incremente ;
 * - **aucun client : on en cree un, deja desactive.** C'est la pierre tombale, et c'est elle qui
 *   rend l'ordre des messages indifferent : le provisionnement arrive ensuite la trouve et
 *   s'arrete, la ou sans elle il creerait un client actif pour un compte supprime.
 *
 * La pierre tombale publie donc `CustomerCreatedEvent` puis `CustomerDisabledEvent` : le client
 * a bien existe, fut-ce une milliseconde, et un consommateur de ces evenements doit voir les deux.
 */
final readonly class DisableCustomerForAccountCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(DisableCustomerForAccountCommand $command): void
    {
        $userAccountId = UserAccountId::fromString($command->userAccountId);
        $customerId = $this->repository->nextIdentity();

        $this->transactional->transactional(function () use ($userAccountId, $customerId): void {
            $now = $this->clock->now();
            $customer = $this->repository->findByUserAccountId($userAccountId);

            if (null !== $customer && $customer->getStatus()->isDisabled()) {
                return;
            }

            $customer ??= Customer::create(
                id: $customerId,
                now: $now,
                userAccountId: $userAccountId,
            );

            $customer->disable($now);

            $this->repository->save($customer);
            $this->eventBus->publishAll($customer->releaseEvents());
        });
    }
}
