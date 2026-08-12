<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\DataFixtures\dev\Catalog;

use App\Infrastructure\Persistence\Mongo\Catalog\CategoryDocument;
use App\Infrastructure\Persistence\Mongo\Catalog\ProductDocument;
use App\Infrastructure\Symfony\DataFixtures\DataFixturesTrait;
use Doctrine\Bundle\MongoDBBundle\Fixture\Fixture;
use Doctrine\Bundle\MongoDBBundle\Fixture\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ProductFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    use DataFixturesTrait;

    public const int NB_PRODUCT = 1000;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $usedSlugs = [];

        $products = $this->seedProducts();
        $imageNames = array_column($products, 'imageName');

        for ($i = count($products); $i < self::NB_PRODUCT; ++$i) {
            $products[] = [
                'title' => $faker->unique()->sentence(3),
                'subtitle' => $faker->sentence($faker->numberBetween(3, 6)),
                // Le prix est stocke en centimes, comme la valeur portee par `Money`.
                'price' => $faker->numberBetween(500, 10000),
                'imageName' => $imageNames[array_rand($imageNames)],
            ];
        }

        /** @var array<string, int> $productsPerCategory */
        $productsPerCategory = [];

        foreach ($products as $index => $value) {
            $level = $faker->numberBetween(0, 3);
            $max = (int) constant(CategoryFixtures::class . '::NB_LEVEL_' . $level);
            $category = $this->getReference(
                'shop_category_level_' . $level . '_' . $faker->numberBetween(1, $max),
                CategoryDocument::class,
            );

            $timestamps = $this->generateTimestamps();

            $product = new ProductDocument();
            $product->id = Uuid::uuid4()->toString();
            $product->title = $value['title'];
            $product->subtitle = $value['subtitle'];
            $product->description = $faker->realText($faker->numberBetween(100, 1000));
            $product->priceAmount = $value['price'];
            $product->priceCurrency = 'EUR';
            $product->slug = $this->uniqueSlug($value['title'], $usedSlugs);
            $product->categoryId = $category->id;
            $product->imageName = $value['imageName'];
            $product->createdAt = $timestamps['createdAt'];
            $product->updatedAt = $timestamps['updatedAt'];

            $productsPerCategory[$category->id] = ($productsPerCategory[$category->id] ?? 0) + 1;

            $this->addReference('shop_product_' . ($index + 1), $product);

            $manager->persist($product);
        }

        // `nbProduct` est denormalise sur la categorie : il est maintenu par les cas
        // d'usage a l'execution, donc c'est a la fixture de le poser elle-meme. Le
        // compter en memoire evite les 30 requetes d'agregation que ferait un recompte.
        foreach ($productsPerCategory as $categoryId => $count) {
            $category = $manager->find(CategoryDocument::class, $categoryId);
            if ($category instanceof CategoryDocument) {
                $category->nbProduct = $count;
            }
        }

        $manager->flush();

        $this->publishSeedImages();
    }

    /**
     * @return list<array{title: string, subtitle: string, price: int, imageName: string}>
     */
    private function seedProducts(): array
    {
        return [
            ['title' => 'Bonnet rouge', 'subtitle' => "Le bonnet parfait pour l'hiver", 'price' => 900, 'imageName' => 'bonnet1.jpg'],
            ['title' => 'Le Bonnet du skieur', 'subtitle' => 'Le bonnet parfait pour le ski', 'price' => 1200, 'imageName' => 'bonnet2.jpg'],
            ['title' => "L'écharpe du lover", 'subtitle' => "L'écharpe parfaite pour les soirées romantiques", 'price' => 1900, 'imageName' => 'echarpe1.jpg'],
            ['title' => "L'écharpe du samedi soir", 'subtitle' => "L'écharpe parfaite pour vos week-ends", 'price' => 1400, 'imageName' => 'echarpe2.jpg'],
            ['title' => 'Le manteau de soirée', 'subtitle' => 'Le manteau martiniquais pour vos soirées', 'price' => 6900, 'imageName' => 'manteau1.jpg'],
            ['title' => 'Le manteau famille', 'subtitle' => 'Le manteau pour vos sorties en famille', 'price' => 7990, 'imageName' => 'manteau2.jpg'],
            ['title' => 'Le T-Shirt manche longue', 'subtitle' => 'Le T-Shirt taillé pour les hommes', 'price' => 1490, 'imageName' => 'tshirt2.jpg'],
            ['title' => 'Le T-Shirt basique', 'subtitle' => 'Le T-Shirt basique parfait pour les hommes', 'price' => 990, 'imageName' => 'tshirt1.jpg'],
        ];
    }

    /**
     * Les fixtures ecrivent des noms de fichiers ; sans les fichiers eux-memes, tous les
     * `imageUrl` du catalogue de dev pointeraient vers des 404. Les visuels de reference
     * vivent dans `assets/`, versionnes ; `public/uploads/` ne l'est pas.
     */
    private function publishSeedImages(): void
    {
        $source = $this->projectDir . '/assets/images/shop/product';
        $destination = $this->projectDir . '/public/uploads/images/shop/product';

        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($destination) && !mkdir($destination, 0o775, true) && !is_dir($destination)) {
            return;
        }

        foreach ($this->seedProducts() as $product) {
            $file = $product['imageName'];

            if (is_file($source . '/' . $file) && !is_file($destination . '/' . $file)) {
                copy($source . '/' . $file, $destination . '/' . $file);
            }
        }
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [CategoryFixtures::class];
    }

    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['dev'];
    }
}
