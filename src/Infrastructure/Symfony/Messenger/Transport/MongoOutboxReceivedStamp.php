<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Transport;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Retient l'identifiant du document reserve, pour que `ack()` et `reject()` sachent quelle
 * ligne d'outbox effacer.
 *
 * `NonSendableStampInterface` : ce tampon designe une ligne de cette file-ci. Le laisser
 * voyager dans le message re-serialise ferait pointer un retry sur un document deja efface.
 */
final readonly class MongoOutboxReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        private string $id,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }
}
