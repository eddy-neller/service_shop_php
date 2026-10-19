<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration;

use DateTimeImmutable;

/**
 * Fait public annonce par `service_identity` sur le bus inter-services, en version 1 du contrat.
 *
 * Ce sont des messages **d'infrastructure**, et c'est pour cela qu'ils vivent ici : ils decrivent
 * ce qui s'est passe chez l'emetteur, dans son vocabulaire, sans aucune classe partagee entre les
 * deux services. Les traduire en commande est le travail des handlers.
 *
 * > **Ne jamais les faire implementer `DomainEventInterface`.** Ce ne sont pas des evenements de ce
 * > domaine, et la consequence serait silencieuse mais totale : `LogDomainEventHandler` declare
 * > `sign: true`, donc Messenger exige une signature HMAC de `kernel.secret` pour tout message de
 * > ce type. `service_identity` n'a pas notre `APP_SECRET` — chaque message serait refuse a la
 * > lecture, et un `InvalidMessageSignatureException` ferait supprimer le message de la file.
 *
 * `eventId` est frappe par l'emetteur et ne doit **jamais** etre regenere ici : c'est la cle de
 * deduplication de l'inbox, et il doit rester identique a chaque redelivrance.
 */
abstract readonly class UserIntegrationEvent
{
    public function __construct(
        public string $eventId,
        public string $userAccountId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
