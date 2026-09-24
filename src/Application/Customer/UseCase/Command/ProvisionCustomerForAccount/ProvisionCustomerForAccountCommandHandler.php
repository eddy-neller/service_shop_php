<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\ProvisionCustomerForAccount;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\UserAccountId;

/**
 * Reaction a la naissance d'un compte utilisateur, declenchee par un message du bus.
 *
 * **Commutatif avec `DisableCustomerForAccount`, et idempotent.** Le bus livre au-moins-une-fois et
 * sans ordre garanti : ce handler doit donc supporter d'etre rejoue, et d'arriver *apres* la
 * desactivation du meme compte.
 *
 * D'ou l'unique regle : **un client deja rattache a ce compte arrete le traitement**, qu'il soit
 * actif ou desactive. Reactiver ici ressusciterait le client d'un compte supprime entre-temps —
 * la pierre tombale posee par `DisableCustomerForAccount` doit rester en place.
 *
 * `findByUserAccountId()` est un check-then-act : deux messages concurrents peuvent le franchir
 * ensemble. Ce qui garantit reellement l'unicite est l'index unique du document ; le second
 * `save()` echoue alors, le message est rejoue, et la relecture tombe sur le client cree entre
 * temps. Le chemin nominal est donc dedoublonne, et la course a une issue correcte.
 */
final readonly class ProvisionCustomerForAccountCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(ProvisionCustomerForAccountCommand $command): void
    {
        $userAccountId = UserAccountId::fromString($command->userAccountId);
        $customerId = $this->repository->nextIdentity();

        $this->transactional->transactional(function () use ($userAccountId, $customerId): void {
            if (null !== $this->repository->findByUserAccountId($userAccountId)) {
                return;
            }

            $customer = Customer::create(
                id: $customerId,
                now: $this->clock->now(),
                userAccountId: $userAccountId,
            );

            $this->repository->save($customer);
            $this->eventBus->publishAll($customer->releaseEvents());
        });
    }
}
