<?php

declare(strict_types=1);

namespace App\Application\Catalog\Port;

use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductTitle;

interface ProductRepositoryInterface
{
    public const array SORT_FIELDS = ['title', 'category.title', 'price', 'createdAt'];

    public function nextIdentity(): ProductId;

    public function list(array $filters, array $orderBy, int $page, int $itemsPerPage): array;

    public function save(Product $product): void;

    public function delete(Product $product): void;

    public function findById(ProductId $id): ?Product;

    public function findWithCategoryById(ProductId $id): ?array;

    public function findByTitle(ProductTitle $title): ?Product;
}
