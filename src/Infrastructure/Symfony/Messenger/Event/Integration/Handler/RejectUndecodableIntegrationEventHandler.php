<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration\Handler;

use App\Infrastructure\Symfony\Messenger\Event\Integration\UndecodableIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserIntegrationEventSerializer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Range un message du bus qui viole le contrat dans `failed_user_events`, au lieu de le perdre.
 *
 * Il **rejette** : `UnrecoverableMessageHandlingException` fait sauter le retry, et Messenger envoie
 * le message directement dans la file d'échec, corps complet compris. Le message SQS est ensuite
 * acquitté, et le worker continue — avant, le décodage levait, le message était supprimé sans trace
 * durable et le worker mourait. Le journal porte une ligne `critical` « Removing from transport »,
 * avec la raison : c'est la trace à chercher.
 *
 * ## Il relit le corps à chaque passage
 *
 * Au premier passage, la relecture échoue comme le décodage qui a produit ce message : il est rejeté.
 * Mais le cas le plus probable d'un refus est **notre** lecture du contrat, trop stricte ou en retard
 * sur l'émetteur. Une fois le sérialiseur corrigé, `messenger:failed:retry --transport=failed_user_events`
 * relit le même corps avec le nouveau code : s'il passe, le fait est traité comme s'il venait
 * d'arriver — inbox et déduplication comprises. S'il ne passe toujours pas, il retourne dans la file
 * d'échec. Aucun chemin de reprise à part, aucun corps à recopier à la main.
 *
 * Un message relu est dispatché **synchronement** sur `event.bus` : les messages d'intégration n'y
 * sont routés vers aucun transport, leur handler s'exécute donc ici même.
 *
 * ## Le sérialiseur injecté est celui du transport, décorateur compris
 *
 * Typé `SerializerInterface`, jamais `UserIntegrationEventSerializer` : un handler du service déclare
 * `sign: true`, et Symfony **décore** alors le sérialiseur de chaque transport par
 * `SigningSerializer`. L'identifiant du service ne désigne donc plus la classe concrète, et la typer
 * fait échouer la construction du handler **à l'exécution**, par un `TypeError` que Messenger prend
 * pour un échec ordinaire — six retries, puis la file d'échec, sans que rien ne compile de travers.
 * Vécu lors de la vérification de bout en bout ; `IntegrationHandlersWiringTest` le garde. C'est
 * aussi la bonne lecture : le corps est relu exactement comme le transport l'a lu.
 *
 * Pas de `sign: true`, comme tous les handlers d'intégration : le corps vient d'un service qui n'a
 * pas notre secret.
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class RejectUndecodableIntegrationEventHandler
{
    public function __construct(
        #[Autowire(service: UserIntegrationEventSerializer::class)]
        private SerializerInterface $serializer,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
    ) {
    }

    public function __invoke(UndecodableIntegrationEvent $event): void
    {
        $envelope = $this->serializer->decode(['body' => $event->body, 'headers' => []]);
        $reread = $envelope->getMessage();

        if ($reread instanceof UndecodableIntegrationEvent) {
            throw new UnrecoverableMessageHandlingException(sprintf('Undecodable user integration event kept in the failure transport: %s', $reread->reason));
        }

        $this->eventBus->dispatch($envelope);
    }
}
