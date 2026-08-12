<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\CreateProductByAdmin;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\SlugGeneratorInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\ProductTitleAlreadyUsedException;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;

final readonly class CreateProductByAdminCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private CategoryRepositoryInterface $categoryRepository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private SlugGeneratorInterface $slugGenerator,
    ) {
    }

    public function handle(CreateProductByAdminCommand $command): ProductItem
    {
        $id = $this->productRepository->nextIdentity();
        $title = ProductTitle::fromString($command->title);
        $subtitle = ProductSubtitle::fromString($command->subtitle);
        $description = ProductDescription::fromString($command->description);
        $price = Money::fromEuros($command->price);
        $slug = $this->slugGenerator->generate($title->toString());
        $categoryId = CategoryId::fromString($command->categoryId);

        return $this->transactional->transactional(function () use ($id, $title, $subtitle, $description, $price, $slug, $categoryId): ProductItem {
            if (null !== $this->productRepository->findByTitle($title)) {
                throw new ProductTitleAlreadyUsedException();
            }

            $category = $this->categoryRepository->findById($categoryId);
            if (null === $category) {
                throw new CategoryNotFoundException();
            }

            $now = $this->clock->now();

            $product = Product::create(
                id: $id,
                title: $title,
                subtitle: $subtitle,
                description: $description,
                price: $price,
                slug: $slug,
                categoryId: $categoryId,
                now: $now,
            );

            $this->productRepository->save($product);

            $category->increaseProductCount($now);
            $this->categoryRepository->save($category);

            return ProductItem::fromProduct($product, $category);
        });
    }
}
