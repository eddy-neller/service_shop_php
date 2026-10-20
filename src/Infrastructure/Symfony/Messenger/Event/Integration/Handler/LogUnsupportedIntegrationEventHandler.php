<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration\Handler;

use App\Infrastructure\Symfony\Messenger\Event\Integration\UnsupportedIntegrationEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Journalise un message du bus que ce service ne sait pas traiter, et l'acquitte.
 *
 * C'est le seul effet attendu : un topic SNS laisse l'emetteur publier un type de plus, ou une
 * version de plus, sans attendre le deploiement de ses abonnes. Echouer la-dessus remplirait la
 * file d'echec a chaque evenement qui ne nous concerne pas.
 *
 * Niveau `warning` et non `info` : rien n'est casse, mais ce service rate peut-etre un fait qui
 * l'interesse. C'est la trace a chercher le jour ou un comportement attendu n'arrive pas.
 *
 * Pas de ledger : reecrire une ligne de log lors d'une redelivrance est sans consequence, et
 * `event_id` permet justement de reperer les rejeux.
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class LogUnsupportedIntegrationEventHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UnsupportedIntegrationEvent $event): void
    {
        $this->logger->warning('Unsupported integration event acknowledged', [
            'event_id' => $event->eventId,
            'type' => $event->type,
            'version' => $event->version,
        ]);
    }
}
