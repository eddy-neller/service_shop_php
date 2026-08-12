<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Database;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests qui touchent un vrai MongoDB.
 *
 * Ces tests ecrivent dans `service_shop_test` (cf. `.env.test`), jamais dans la base de
 * developpement, et repartent d'une base vide a chaque cas.
 *
 * Les agregats sont fabriques par les repositories eux-memes (`nextIdentity()`) plutot
 * que par des documents poses a la main : c'est le chemin reel des cas d'usage, donc le
 * seul qui exerce le mapping.
 */
abstract class MongoPersistenceTestCase extends KernelTestCase
{
    protected DocumentManager $documentManager;

    protected ProductRepositoryInterface $products;

    protected CategoryRepositoryInterface $categories;

    protected TransactionalInterface $transactional;

    /** Les index du mapping ne sont poses qu'une fois par processus (cf. resetDatabase()). */
    private static bool $indexesEnsured = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $documentManager = $container->get(DocumentManager::class);
        self::assertInstanceOf(DocumentManager::class, $documentManager);
        $this->documentManager = $documentManager;

        $products = $container->get(ProductRepositoryInterface::class);
        self::assertInstanceOf(ProductRepositoryInterface::class, $products);
        $this->products = $products;

        $categories = $container->get(CategoryRepositoryInterface::class);
        self::assertInstanceOf(CategoryRepositoryInterface::class, $categories);
        $this->categories = $categories;

        $transactional = $container->get(TransactionalInterface::class);
        self::assertInstanceOf(TransactionalInterface::class, $transactional);
        $this->transactional = $transactional;

        $this->resetDatabase();
    }

    protected function aCategory(string $title): Category
    {
        return Category::create(
            id: $this->categories->nextIdentity(),
            title: CategoryTitle::fromString($title),
            slug: Slug::fromString($this->slugify($title)),
            now: new DateTimeImmutable(),
        );
    }

    protected function aProduct(string $title, CategoryId $categoryId): Product
    {
        return Product::create(
            id: $this->products->nextIdentity(),
            title: ProductTitle::fromString($title),
            subtitle: ProductSubtitle::fromString('Sous-titre de ' . $title),
            description: ProductDescription::fromString('Description de ' . $title),
            price: Money::fromEuros(1299.90),
            slug: Slug::fromString($this->slugify($title)),
            categoryId: $categoryId,
            now: new DateTimeImmutable(),
        );
    }

    protected function slugify(string $value): string
    {
        return strtolower(str_replace(' ', '-', $value));
    }

    protected function database(): Database
    {
        return $this->documentManager->getClient()
            ->selectDatabase($_ENV['MONGODB_DB'] ?? 'service_shop_test');
    }

    /**
     * Vide les collections **sans** les supprimer.
     *
     * Les index doivent exister — `MongoTransactionalTest` s'appuie sur le rejet d'un
     * index unique pour provoquer l'echec de flush qu'il verifie, et sans eux il
     * passerait au vert sans rien prouver. Mais les reposer a chaque test coute 155 ms
     * la ou un `deleteMany()` en coute 0,8 : ils sont donc poses une seule fois par
     * processus, et `deleteMany()` les preserve.
     */
    protected function resetDatabase(): void
    {
        $database = $this->database();

        if (!self::$indexesEnsured) {
            $this->documentManager->getSchemaManager()->ensureIndexes();
            self::$indexesEnsured = true;
        }

        foreach (['product', 'category'] as $collection) {
            $database->selectCollection($collection)->deleteMany([]);
        }

        $this->documentManager->clear();
    }
}
