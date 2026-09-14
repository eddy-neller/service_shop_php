<?php

declare(strict_types=1);

namespace App\Presentation\Shared\Security;

use ApiPlatform\Symfony\Security\Exception\AccessDeniedException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * L'exception levee est celle du composant Security, **pas** `AccessDeniedHttpException`.
 *
 * La premiere est interceptee par l'`ExceptionListener` du pare-feu, qui declenche l'entry
 * point JWT : jeton absent ou invalide donne un **401**. La seconde court-circuite la couche
 * securite et rend un **403** immediat, ce qui masquerait une absence d'authentification
 * derriere un refus de droits.
 */
trait CustomerMeSecurityTrait
{
    abstract protected function getSecurity(): Security;

    protected function getCurrentUserOrThrow(): UserInterface
    {
        $user = $this->getSecurity()->getUser();

        if (!$user instanceof UserInterface) {
            throw new AccessDeniedException('Utilisateur non authentifie.');
        }

        return $user;
    }

    protected function getUserIdFromAuthenticatedUser(UserInterface $user): string
    {
        if (!method_exists($user, 'getId')) {
            throw new AccessDeniedException('Utilisateur invalide.');
        }

        $id = $user->getId();

        if (!method_exists($id, 'toString')) {
            throw new AccessDeniedException('Identifiant utilisateur invalide.');
        }

        return $id->toString();
    }
}
