<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\DataFixtures\dev\Catalog;

use App\Infrastructure\Persistence\Mongo\Catalog\CategoryDocument;
use App\Infrastructure\Symfony\DataFixtures\DataFixturesTrait;
use Doctrine\Bundle\MongoDBBundle\Fixture\Fixture;
use Doctrine\Bundle\MongoDBBundle\Fixture\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Ramsey\Uuid\Uuid;

/**
 * Arbre de categories : 2 racines, puis 4, 8 et 16.
 *
 * Deux differences imposees par MongoDB, et qui meritent d'etre vues :
 *
 * 1. Le `level` est ecrit ici : le nested set de Gedmo le calculerait au
 *    flush ; ici c'est `MongoCategoryRepository::save()` qui s'en charge — mais une
 *    fixture ecrit des documents, pas des agregats, donc elle doit le poser elle-meme.
 * 2. Les titres sont tires en `unique()` : la collection porte un index unique sur
 *    `title`, au-dela du seul `slug`.
 */
class CategoryFixtures extends Fixture implements FixtureGroupInterface
{
    use DataFixturesTrait;

    public const int NB_LEVEL_0 = 2;

    public const int NB_LEVEL_1 = 4;

    public const int NB_LEVEL_2 = 8;

    public const int NB_LEVEL_3 = 16;

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $usedSlugs = [];

        /** @var list<CategoryDocument> $parents */
        $parents = [];

        foreach ([self::NB_LEVEL_0, self::NB_LEVEL_1, self::NB_LEVEL_2, self::NB_LEVEL_3] as $level => $count) {
            $current = [];

            for ($i = 1; $i <= $count; ++$i) {
                // Chaque parent recoit au moins un enfant tant qu'il en reste a placer ;
                // au-dela, le rattachement est aleatoire. L'arbre reste donc connexe.
                $parent = [] === $parents
                    ? null
                    : ($parents[$i - 1] ?? $parents[$faker->numberBetween(0, count($parents) - 1)]);

                $category = $this->createCategory(
                    title: $faker->unique()->company(),
                    description: $faker->text(),
                    level: $level,
                    parent: $parent,
                    usedSlugs: $usedSlugs,
                );

                $this->addReference('shop_category_level_' . $level . '_' . $i, $category);
                $current[] = $category;

                $manager->persist($category);
            }

            $parents = $current;
        }

        $manager->flush();
    }

    /**
     * @param array<string, true> $usedSlugs
     */
    private function createCategory(
        string $title,
        string $description,
        int $level,
        ?CategoryDocument $parent,
        array &$usedSlugs,
    ): CategoryDocument {
        $timestamps = $this->generateTimestamps();

        $category = new CategoryDocument();
        $category->id = Uuid::uuid4()->toString();
        $category->title = $title;
        $category->description = $description;
        $category->slug = $this->uniqueSlug($title, $usedSlugs);
        $category->parentId = $parent?->id;
        $category->level = $level;
        $category->nbProduct = 0;
        $category->createdAt = $timestamps['createdAt'];
        $category->updatedAt = $timestamps['updatedAt'];

        return $category;
    }

    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['dev'];
    }
}
