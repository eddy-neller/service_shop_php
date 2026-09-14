<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayProduct;

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
    ) {
    }

    public function handle(DisplayProductQuery $query): ProductItem
    {
        $result = $this->productRepository->findWithCategoryById(ProductId::fromString($query->productId));

        if (null === $result) {
            throw new ProductNotFoundException();
        }

        $category = $result['category'];
        if (null === $category) {
            throw new CategoryNotFoundException();
        }

        return ProductItem::fromProduct($result['product'], $category);
    }
}
