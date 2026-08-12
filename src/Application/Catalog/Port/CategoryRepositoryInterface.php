<?php

declare(strict_types=1);

namespace App\Application\Catalog\Port;

use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;

interface CategoryRepositoryInterface
{
    public const array SORT_FIELDS = ['title', 'level', 'nbProduct', 'createdAt'];

    public function nextIdentity(): CategoryId;

    /**
     * @return array{items: list<Category>, totalItems: int, totalPages: int}
     */
    public function list(array $filters, array $orderBy, int $page, int $itemsPerPage): array;

    public function save(Category $category): void;

    public function delete(Category $category): void;

    public function findById(CategoryId $id): ?Category;

    public function findByTitle(CategoryTitle $title): ?Category;

    /**
     * @return array{category: Category, parent: ?Category, children: ?list<Category>}|null
     */
    public function findTreeById(CategoryId $id): ?array;
}
