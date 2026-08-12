<?php

declare(strict_types=1);

namespace App\Application\Catalog\Port;

use App\Application\Shared\Port\FileInterface;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductTitle;

interface ProductRepositoryInterface
{
    public const array SORT_FIELDS = ['title', 'category.title', 'price', 'createdAt'];

    public function nextIdentity(): ProductId;

    /**
     * @return array{items: list<array{product: Product, category: Category}>, totalItems: int, totalPages: int}
     */
    public function list(array $filters, array $orderBy, int $page, int $itemsPerPage): array;

    public function save(Product $product): void;

    public function delete(Product $product): void;

    public function findById(ProductId $id): ?Product;

    public function findByTitle(ProductTitle $title): ?Product;

    /**
     * @param ProductId[] $ids
     *
     * @return Product[]
     */
    public function findByIds(array $ids): array;

    public function updateImage(ProductId $id, FileInterface $file): ?Product;
}
