<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration;

/**
 * `identity.user.registered` — Un visiteur s'est inscrit lui-meme.
 *
 * Une classe par type du contrat : c'est ce qui permet a Messenger de router par type-hint, et a
 * l'inbox de distinguer les reactions par leur classe de handler.
 */
final readonly class UserRegisteredIntegrationEvent extends UserIntegrationEvent
{
}
