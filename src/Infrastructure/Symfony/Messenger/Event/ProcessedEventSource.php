<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event;

/**
 * Provenance d'un evenement deja traite, et duree pendant laquelle il faut s'en souvenir.
 *
 * Une seule collection porte les deux flux — c'est le meme choix que l'outbox, qui partage sa
 * collection avec sa file d'echec et ne s'en distingue que par `queueName`. La cle de
 * deduplication `(eventId, handler)` suffit a les separer : un handler d'evenement d'integration
 * n'est jamais la meme classe qu'un handler de Domain Event interne. Ce champ sert donc a lire la
 * collection en incident et a porter la retention, pas a garantir l'unicite.
 */
enum ProcessedEventSource: string
{
    /** Evenement d'integration recu du bus, emis par `service_identity`. */
    case Bus = 'bus.identity';

    /** Domain Event de ce service, consomme depuis son propre outbox. */
    case Outbox = 'outbox';

    /**
     * Au-dela, la ligne est supprimee par l'index TTL : se souvenir plus longtemps qu'une
     * redelivrance possible ne protege de rien et fait grossir la collection sans fin.
     *
     * - `Bus` : SQS ne conserve pas un message plus de 14 jours, 30 laisse une marge franche ;
     * - `Outbox` : la redelivrance y est de l'ordre de l'heure (`redeliver_timeout`), 7 jours
     *   couvrent en plus un rejeu manuel depuis la file d'echec.
     */
    public function retentionDays(): int
    {
        return match ($this) {
            self::Bus => 30,
            self::Outbox => 7,
        };
    }
}
