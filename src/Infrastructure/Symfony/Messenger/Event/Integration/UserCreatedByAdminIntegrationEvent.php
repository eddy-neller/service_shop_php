<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration;

/**
 * `identity.user.created_by_admin` — Un administrateur a cree le compte.
 *
 * Une classe par type du contrat : c'est ce qui permet a Messenger de router par type-hint, et a
 * l'inbox de distinguer les reactions par leur classe de handler.
 */
final readonly class UserCreatedByAdminIntegrationEvent extends UserIntegrationEvent
{
}
