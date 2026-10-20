<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration\Handler;

use App\Application\Customer\UseCase\Command\DisableCustomerForAccount\DisableCustomerForAccountCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserDeletedIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Desactive le client du compte supprime chez l'emetteur — jamais une suppression : un client
 * porte des commandes, et son historique doit survivre au compte qui l'a cree.
 *
 * Meme contrat que `ProvisionCustomerOnUserCreatedHandler` : traduction en commande, aucune
 * transaction ouverte ici, marquage de l'inbox **apres** l'effet. Et la meme raison de fond : le
 * cas d'usage est commutatif, donc ce message peut arriver **avant** celui qui annonce la creation
 * du compte, auquel cas il pose une pierre tombale qu'un provisionnement tardif respectera.
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class DisableCustomerOnUserDeletedHandler
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private ProcessedEventLedgerInterface $ledger,
    ) {
    }

    public function __invoke(UserDeletedIntegrationEvent $event): void
    {
        if ($this->ledger->hasProcessed($event->eventId, self::class)) {
            return;
        }

        $this->commandBus->dispatch(new DisableCustomerForAccountCommand($event->userAccountId));

        $this->ledger->markProcessedAndFlush($event->eventId, self::class, ProcessedEventSource::Bus);
    }
}
