<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\DataFixtures;

use DateTimeImmutable;
use DateTimeInterface;
use Faker\Factory;
use Ramsey\Uuid\Uuid;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Outillage commun aux fixtures.
 */
trait DataFixturesTrait
{
    /**
     * Horodatage stable des fixtures de test : les donnees chargees manuellement restent
     * reproductibles, sans dependre de l'instant ou la commande est lancee.
     */
    protected function testTimestamp(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-01-01 10:00:00');
    }

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

    /**
     * Identifiant stable pour une fixture : utile aux relations entre documents et aux
     * scenarios relances plusieurs fois sur une meme base.
     */
    protected function fixtureId(string $scope, string $value): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, 'shop-fixture:' . $scope . ':' . $value)->toString();
    }

    /**
     * Slug deterministe pour les jeux de donnees dont les titres sont connus a l'avance.
     */
    protected function fixtureSlug(string $value): string
    {
        return (new AsciiSlugger())->slug($value)->lower()->toString();
    }
}
