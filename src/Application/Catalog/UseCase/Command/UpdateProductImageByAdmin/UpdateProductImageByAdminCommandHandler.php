<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\UpdateProductImageByAdmin;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CatalogDomainException;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\ValueObject\ProductId;

final readonly class UpdateProductImageByAdminCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private CategoryRepositoryInterface $categoryRepository,
        private TransactionalInterface $transactional,
    ) {
    }

    public function handle(UpdateProductImageByAdminCommand $command): ProductItem
    {
        $imageFile = $command->imageFile;

        if (!$imageFile->isValid()) {
            throw new CatalogDomainException('Invalid image file.');
        }

        $productId = ProductId::fromString($command->productId);

        return $this->transactional->transactional(function () use ($productId, $imageFile): ProductItem {
            $product = $this->productRepository->updateImage($productId, $imageFile);

            if (null === $product) {
                throw new ProductNotFoundException();
            }

            $category = $this->categoryRepository->findById($product->getCategoryId());
            if (null === $category) {
                throw new CategoryNotFoundException();
            }

            return ProductItem::fromProduct($product, $category);
        });
    }
}
