<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\DataFixtures\dev\Catalog;

use App\Infrastructure\Persistence\Mongo\Catalog\CategoryDocument;
use App\Infrastructure\Symfony\DataFixtures\DataFixturesTrait;
use Doctrine\Bundle\MongoDBBundle\Fixture\Fixture;
use Doctrine\Bundle\MongoDBBundle\Fixture\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use Ramsey\Uuid\Uuid;

/**
 * Arbre dynamique de categories : 2 racines, puis 4, 8 et 16.
 */
class CategoryFixtures extends Fixture implements FixtureGroupInterface
{
    use DataFixturesTrait;

    public const int NB_LEVEL_1 = 2;

    public const int NB_LEVEL_2 = 4;

    public const int NB_LEVEL_3 = 8;

    public const int NB_LEVEL_4 = 16;

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $usedSlugs = [];

        /** @var list<CategoryDocument> $parents */
        $parents = [];

        foreach (
            [
                1 => self::NB_LEVEL_1,
                2 => self::NB_LEVEL_2,
                3 => self::NB_LEVEL_3,
                4 => self::NB_LEVEL_4,
            ] as $level => $count
        ) {
            $current = [];

            for ($index = 1; $index <= $count; ++$index) {
                $parent = [] === $parents
                    ? null
                    : ($parents[$index - 1] ?? $parents[$faker->numberBetween(0, count($parents) - 1)]);
                $category = $this->createCategory($faker, $parent, $usedSlugs);

                $this->addReference('shop_category_level_' . $level . '_' . $index, $category);
                $current[] = $category;
                $manager->persist($category);
            }

            $parents = $current;
        }

        $manager->flush();
    }

    private function createCategory(
        Generator $faker,
        ?CategoryDocument $parent,
        array &$usedSlugs,
    ): CategoryDocument {
        $timestamps = $this->generateTimestamps();
        $title = $faker->unique()->company();

        $category = new CategoryDocument();
        $category->id = Uuid::uuid4()->toString();
        $category->title = $title;
        $category->description = $faker->text();
        $category->slug = $this->uniqueSlug($title, $usedSlugs);
        $category->parent = $parent;
        $category->nbProduct = 0;
        $category->createdAt = $timestamps['createdAt'];
        $category->updatedAt = $timestamps['updatedAt'];

        return $category;
    }

    public static function getGroups(): array
    {
        return ['dev'];
    }
}
