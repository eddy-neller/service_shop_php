<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Outbox;

/**
 * Une ligne d'outbox rendue au transport : son identifiant et le message serialise.
 *
 * Volontairement pauvre. Les colonnes denormalisees (`eventName`, `aggregateId`…) existent
 * pour la lecture humaine de la collection, pas pour le transport, qui n'a besoin que de
 * quoi reconstruire une `Envelope` et de quoi acquitter.
 */
final readonly class OutboxRecord
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $id,
        public string $body,
        public array $headers,
    ) {
    }
}
