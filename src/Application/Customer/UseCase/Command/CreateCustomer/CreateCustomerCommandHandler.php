<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Command\CreateCustomer;

use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\ReadModel\CustomerItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Customer\Exception\CustomerAlreadyExistsException;
use App\Domain\Customer\Model\Customer;
use App\Domain\Customer\ValueObject\UserAccountId;

/**
 * Le monolithe chargeait ici l'utilisateur pour verifier son existence. Cette verification
 * a disparu, et c'est deliberé :
 *
 * - elle ne lisait **aucun** champ de l'utilisateur — `Customer` ne copie rien de `User`,
 *   sa seule donnee d'identite est le UUID, qui arrive dans la commande ;
 * - la refaire a distance obligerait ce service a rappeler l'emetteur, donc a dependre de
 *   sa disponibilite — exactement la propriete que `GET /health` existe pour verifier.
 *
 * `userAccountId` devient donc un identifiant de correlation opaque et non valide, sans cle
 * etrangere : c'est ce que la feuille de route annonce. Une saisie erronee cree un client
 * orphelin qu'aucun utilisateur ne peut atteindre, puisque `DisplayMyCustomer` recherche sur
 * le `sub` du jeton.
 *
 * Le `findByUserAccountId()` ci-dessous est un check-then-act : il rend un 409 lisible, mais
 * ce qui garantit reellement l'unicite est l'index unique porte par le document.
 */
final readonly class CreateCustomerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(CreateCustomerCommand $command): CustomerItem
    {
        $userAccountId = UserAccountId::fromString($command->userAccountId);
        $customerId = $this->repository->nextIdentity();

        return $this->transactional->transactional(function () use ($userAccountId, $customerId): CustomerItem {
            if (null !== $this->repository->findByUserAccountId($userAccountId)) {
                throw new CustomerAlreadyExistsException();
            }

            $customer = Customer::create(
                id: $customerId,
                now: $this->clock->now(),
                userAccountId: $userAccountId,
            );

            $this->repository->save($customer);
            $this->eventBus->publishAll($customer->releaseEvents());

            return CustomerItem::fromCustomer($customer);
        });
    }
}
