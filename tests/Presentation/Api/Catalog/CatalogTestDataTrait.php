<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Catalog;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Shared\Port\TransactionalInterface;
use RuntimeException;

trait CatalogTestDataTrait
{
    protected function seedCatalogTestData(): void
    {
        $container = static::getContainer();
        $categories = $container->get(CategoryRepositoryInterface::class);
        $products = $container->get(ProductRepositoryInterface::class);
        $transactional = $container->get(TransactionalInterface::class);

        if (
            !$categories instanceof CategoryRepositoryInterface
            || !$products instanceof ProductRepositoryInterface
            || !$transactional instanceof TransactionalInterface
        ) {
            throw new RuntimeException('Catalog test data services not found.');
        }

        (new CategoryTestDataSeeder($categories, $transactional))->seed();
        (new ProductTestDataSeeder($categories, $products, $transactional))->seed();
        $this->getManager()->clear();
    }
}
