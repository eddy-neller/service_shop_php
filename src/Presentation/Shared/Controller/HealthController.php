<?php

declare(strict_types=1);

namespace App\Presentation\Shared\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « L'application demarre » : nginx, php-fpm, le noyau Symfony et sa configuration repondent.
 *
 * Contrat, identique dans `service_identity` :
 * - aucune dependance interrogee (ni MongoDB, ni Redis) : une panne d'infrastructure ne fait pas
 *   echouer cette route ;
 * - publique (`access_control`), jamais routee par la passerelle ;
 * - `Cache-Control: no-store` explicite. En production, Varnish porte `service-shop` : sans cet
 *   en-tete, seule la valeur par defaut de Symfony (`no-cache, private`) l'empecherait de servir
 *   un « ok » perime.
 *
 * Ce n'est **pas** une sonde liveness : elle execute le code applicatif, listeners compris — un
 * `LocaleListener` defaillant l'a deja fait repondre 500 — et une liveness qui echoue redemarre le
 * conteneur en boucle. La liveness de php-fpm reposera sur son ping natif (feuille de route, etape 2).
 */
#[AsController]
final readonly class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'ok', 'service' => 'service_shop'],
            JsonResponse::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
