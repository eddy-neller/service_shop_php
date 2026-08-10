<?php

declare(strict_types=1);

namespace App\Security;

use Deprecated;
use InvalidArgumentException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\JWTUserInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Copie conforme de App\Infrastructure\Security\JwtAuthenticatedUser du monolithe.
 *
 * Cette classe est la raison pour laquelle ce service peut authentifier sans base :
 * l'utilisateur est integralement reconstruit depuis les claims `sub` et `roles`,
 * sans repository ni requete. Ne pas y ajouter de dependance.
 */
final readonly class JwtAuthenticatedUser implements JWTUserInterface
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        private UuidInterface $id,
        private array $roles,
    ) {
    }

    public static function createFromPayload($username, array $payload): JWTUserInterface
    {
        if (!is_string($username) || !Uuid::isValid($username)) {
            throw new InvalidArgumentException('JWT subject must be a valid UUID.');
        }

        $roles = $payload['roles'] ?? [];
        if (!is_array($roles)) {
            throw new InvalidArgumentException('JWT roles must be a list of strings.');
        }

        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new InvalidArgumentException('JWT roles must be a list of strings.');
            }
        }

        return new self(Uuid::fromString($username), array_values($roles));
    }

    public function getId(): UuidInterface
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->id->toString();
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    #[Deprecated]
    public function eraseCredentials(): void
    {
    }
}
