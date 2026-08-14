<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Customer;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Security\Core\User\UserInterface;

trait CustomerUserTrait
{
    /**
     * Creates a lightweight user double exposing getId() so UserMeSecurityTrait works in tests.
     */
    private function createUser(string $id): UserInterface
    {
        return new class($id) implements UserInterface {
            public function __construct(private string $id)
            {
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'user@example.com';
            }

            public function getId(): UuidInterface
            {
                return Uuid::fromString($this->id);
            }
        };
    }
}
