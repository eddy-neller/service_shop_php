<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Symfony\Security\JwtAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PingController extends AbstractController
{
    /**
     * Preuve que la chaine d'authentification complete fonctionne : le service
     * restitue l'identite portee par un token qu'il n'a pas emis et dont il ne
     * peut verifier que la signature.
     */
    #[Route('/ping', name: 'ping', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function ping(#[CurrentUser] JwtAuthenticatedUser $user): JsonResponse
    {
        return new JsonResponse([
            'service' => 'service_shop',
            'userId' => $user->getId()->toString(),
            'roles' => $user->getRoles(),
        ]);
    }
}
