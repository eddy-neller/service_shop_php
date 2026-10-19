<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration;

/**
 * Message du bus que ce service ne sait pas traiter : type inconnu, ou version du contrat
 * posterieure a celle qu'il connait.
 *
 * Il existe pour que ce cas soit **journalise puis acquitte**, et non rejoue indefiniment. C'est
 * la contrepartie d'un topic SNS : l'emetteur peut publier un type de plus, ou une version de plus,
 * sans attendre que tous ses abonnes soient deployes. Un consommateur qui echouerait la-dessus
 * remplirait sa file d'echec a chaque evenement d'un type qui ne le concerne pas.
 *
 * Le corps brut n'est pas conserve : il part avec le message acquitte. Ce qui reste est la ligne de
 * log du handler, avec l'`eventId` qui permet de retrouver l'evenement chez l'emetteur.
 */
final readonly class UnsupportedIntegrationEvent
{
    public function __construct(
        public string $eventId,
        public string $type,
        public int $version,
    ) {
    }
}
