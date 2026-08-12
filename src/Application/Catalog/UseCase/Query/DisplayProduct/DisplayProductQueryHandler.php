<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayProduct;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\ValueObject\ProductId;

final readonly class DisplayProductQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private CategoryRepositoryInterface $categoryRepository,
    ) {
    }

    public function handle(DisplayProductQuery $query): ProductItem
    {
        $product = $this->productRepository->findById(ProductId::fromString($query->productId));

        if (null === $product) {
            throw new ProductNotFoundException();
        }

        $category = $this->categoryRepository->findById($product->getCategoryId());
        if (null === $category) {
            throw new CategoryNotFoundException();
        }

        return ProductItem::fromProduct($product, $category);
    }
}
