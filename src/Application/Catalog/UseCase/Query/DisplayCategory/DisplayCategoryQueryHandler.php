<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayCategory;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\ValueObject\CategoryId;

final readonly class DisplayCategoryQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
    ) {
    }

    public function handle(DisplayCategoryQuery $query): CategoryItem
    {
        $categoryTree = $this->categoryRepository->findTreeById(CategoryId::fromString($query->categoryId));

        if (null === $categoryTree) {
            throw new CategoryNotFoundException();
        }

        return CategoryItem::fromCategory(
            category: $categoryTree['category'],
            parent: $categoryTree['parent'],
            children: $categoryTree['children'],
        );
    }
}
