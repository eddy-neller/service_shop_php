<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\DataFixtures;

use DateTimeImmutable;
use DateTimeInterface;
use Faker\Factory;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Outillage commun aux fixtures.
 *
 * Les helpers `getUsers()` / `getTestUsers()`
 * n'ont pas suivi, ce service n'ayant pas d'utilisateurs — l'identite vient du token.
 */
trait DataFixturesTrait
{
    /**
     * @return array{createdAt: DateTimeImmutable, updatedAt: DateTimeImmutable}
     */
    public function generateTimestamps(?DateTimeInterface $createdAt = null): array
    {
        $faker = Factory::create();

        $createdAt ??= $faker->dateTimeBetween('-20 years', '-2 days');
        $updatedAt = $faker->dateTimeBetween($createdAt, '-1 days');

        return [
            'createdAt' => $createdAt instanceof DateTimeImmutable
                ? $createdAt
                : DateTimeImmutable::createFromMutable($createdAt),
            'updatedAt' => $updatedAt instanceof DateTimeImmutable
                ? $updatedAt
                : DateTimeImmutable::createFromMutable($updatedAt),
        ];
    }

    /**
     * Les collections portent un index unique sur `slug`. Deux titres distincts peuvent
     * pourtant produire le meme slug — et une fixture qui echoue une fois sur dix, sur
     * un tirage aleatoire, est pire qu'un slug suffixe.
     *
     * @param array<string, true> $used passe par reference : le registre des slugs deja pris
     */
    private function uniqueSlug(string $title, array &$used): string
    {
        $base = (new AsciiSlugger())->slug($title)->lower()->toString();

        $slug = $base;
        $suffix = 2;
        while (isset($used[$slug])) {
            $slug = $base . '-' . $suffix;
            ++$suffix;
        }

        $used[$slug] = true;

        return $slug;
    }
}
