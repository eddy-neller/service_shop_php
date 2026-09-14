<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Security;

/**
 * Vocabulaire des roles portes par le claim `roles` du JWT.
 *
 * Volontairement reduit a des constantes : contrairement au VO homonyme du service
 * emetteur, ce service ne *valide* pas les roles — il les recoit deja signes. Toute
 * validation ici serait une seconde source de verite, qui divergerait le jour ou
 * l'emetteur ajouterait un role.
 *
 * La hierarchie associee vit dans `config/packages/security.yaml`.
 */
final class RoleSet
{
    public const string ROLE_USER = 'ROLE_USER';

    public const string ROLE_MODERATEUR = 'ROLE_MODERATEUR';

    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    public const string ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    private function __construct()
    {
    }
}
