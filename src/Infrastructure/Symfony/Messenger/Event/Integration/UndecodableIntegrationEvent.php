<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration;

/**
 * Un message du bus qui viole le contrat, **conservé** au lieu d'être perdu.
 *
 * Le sérialiseur le rend à la place d'une exception : levée pendant le décodage, celle-ci faisait
 * supprimer le message de la file par `AmazonSqsReceiver`, sans file d'échec ni DLQ, et tuait le
 * worker. Devenu un message ordinaire, il suit le chemin des échecs de handler et finit dans
 * `failed_user_events`, où `messenger:failed:show` le montre et `messenger:failed:retry` le rejoue.
 *
 * `body` est le corps **complet**, tel que reçu : c'est lui qui permettra de le relire une fois la
 * lecture corrigée, ou de comprendre ce que l'émetteur a publié. Aucune donnée personnelle n'est
 * censée y circuler — le contrat ne transporte que l'identifiant du compte.
 *
 * Ce n'est **pas** un `DomainEventInterface`, pour la même raison que les autres messages
 * d'intégration : son handler ne déclare pas `sign: true`, l'émetteur n'ayant pas notre secret.
 */
final readonly class UndecodableIntegrationEvent
{
    public function __construct(
        public string $body,
        public string $reason,
    ) {
    }
}
