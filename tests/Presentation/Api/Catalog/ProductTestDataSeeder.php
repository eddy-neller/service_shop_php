<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Catalog;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use LogicException;

final readonly class ProductTestDataSeeder
{
    public const string ANCHOR_PRODUCT_TITLE = 'Product title 1';

    public function __construct(
        private CategoryRepositoryInterface $categories,
        private ProductRepositoryInterface $products,
        private TransactionalInterface $transactional,
    ) {
    }

    public function seed(): void
    {
        $now = new DateTimeImmutable();

        $this->transactional->transactional(function () use ($now): void {
            $category = $this->categories->findByTitle(CategoryTitle::fromString(CategoryTestDataSeeder::ANCHOR_CATEGORY_TITLE));
            if (null === $category) {
                throw new LogicException('Product test data requires the category test data.');
            }

            for ($i = 1; $i <= 6; ++$i) {
                $title = 'Product title ' . $i;
                $product = Product::create(
                    id: $this->products->nextIdentity(),
                    title: ProductTitle::fromString($title),
                    subtitle: ProductSubtitle::fromString('Sous-titre de ' . $title),
                    description: ProductDescription::fromString('Description de ' . $title),
                    price: Money::fromEuros(19.99),
                    slug: Slug::fromString($this->slugify($title)),
                    categoryId: $category->getId(),
                    now: $now,
                );
                $product->updateImage(md5($title) . '.jpg', $now);

                $this->products->save($product);
                $category->increaseProductCount($now);
            }

            $this->categories->save($category);
        });
    }

    private function slugify(string $value): string
    {
        return strtolower(str_replace([' ', "'"], ['-', ''], $value));
    }
}
