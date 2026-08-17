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

    public function list(array $filters, array $orderBy, int $page, int $itemsPerPage): array;

    public function save(Category $category): void;

    public function delete(Category $category): void;

    public function findById(CategoryId $id): ?Category;

    public function findByTitle(CategoryTitle $title): ?Category;

    /** Returns true when $candidateId is below $ancestorId in the category tree. */
    public function isDescendantOf(CategoryId $candidateId, CategoryId $ancestorId): bool;

    public function findTreeById(CategoryId $id): ?array;
}
