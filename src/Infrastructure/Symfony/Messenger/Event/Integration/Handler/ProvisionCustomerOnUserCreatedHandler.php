<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration\Handler;

use App\Application\Customer\UseCase\Command\ProvisionCustomerForAccount\ProvisionCustomerForAccountCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserCreatedByAdminIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserRegisteredIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventLedgerInterface;
use App\Infrastructure\Symfony\Messenger\Event\ProcessedEventSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Provisionne le client du compte qui vient de naitre dans `service_identity`.
 *
 * Deux types du contrat, une seule reaction : qu'un visiteur s'inscrive ou qu'un administrateur
 * cree le compte, le shop a besoin du meme client. Les deux methodes partagent donc la cle de
 * deduplication `(eventId, self::class)` — sans risque de collision, chaque evenement portant son
 * propre `eventId`.
 *
 * ## Tout ce que ce handler fait, et surtout ce qu'il ne fait pas
 *
 * Il **traduit** un fait de l'emetteur en commande de ce service, et rien d'autre : pas de
 * decision metier, pas de lecture, pas de transaction. Le cas d'usage est deja ecrit pour etre
 * **commutatif et idempotent** — c'est lui qui sait qu'un client deja rattache arrete le
 * traitement, et que la pierre tombale d'un compte supprime ne doit pas etre ressuscitee.
 *
 * > **Ne pas ouvrir de `transactional()` autour du dispatch.** Le cas d'usage ouvre et valide la
 * > sienne ; une transaction englobante verrait son `flush()` commiter d'abord, et l'atomicite
 * > serait perdue **sans qu'aucune erreur n'apparaisse**.
 *
 * L'inbox est donc marquee **apres** l'effet, par l'ecriture durable immediate — la transaction du
 * cas d'usage etant deja validee a ce moment-la. La garantie est l'au-moins-une-fois dedoublonne :
 * une panne entre le commit et le marquage fait rejouer la commande, que sa convergence rend
 * inoffensive. L'ordre inverse echangerait ce doublon contre une perte definitive.
 *
 * > **Pas de `sign: true` ici**, contrairement aux handlers de Domain Events de ce service. La
 * > signature est un HMAC de `kernel.secret` : exiger une signature sur un type publie par
 * > `service_identity`, qui n'a pas notre `APP_SECRET`, ferait refuser **tous** les messages — et
 * > un message refuse a la lecture est supprime de la file.
 */
final readonly class ProvisionCustomerOnUserCreatedHandler
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private ProcessedEventLedgerInterface $ledger,
    ) {
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onUserRegistered(UserRegisteredIntegrationEvent $event): void
    {
        $this->provision($event);
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onUserCreatedByAdmin(UserCreatedByAdminIntegrationEvent $event): void
    {
        $this->provision($event);
    }

    private function provision(UserIntegrationEvent $event): void
    {
        if ($this->ledger->hasProcessed($event->eventId, self::class)) {
            return;
        }

        $this->commandBus->dispatch(new ProvisionCustomerForAccountCommand($event->userAccountId));

        $this->ledger->markProcessedAndFlush($event->eventId, self::class, ProcessedEventSource::Bus);
    }
}
