<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayListCategory;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Catalog\ReadModel\Catalog\CategoryList;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Application\Shared\ReadModel\Pagination;
use App\Domain\Catalog\Model\Category;

final readonly class DisplayListCategoryQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CategoryRepositoryInterface $repository,
    ) {
    }

    public function handle(DisplayListCategoryQuery $query): CategoryList
    {
        $orderBy = [] !== $query->orderBy ? $query->orderBy : ['createdAt' => 'DESC'];
        $pagination = Pagination::fromRaw($query->page, $query->itemsPerPage);

        $result = $this->repository->list(
            filters: $query->filters,
            orderBy: $orderBy,
            page: $pagination->page,
            itemsPerPage: $pagination->itemsPerPage,
        );

        return new CategoryList(
            items: array_map(static fn (Category $category): CategoryItem => CategoryItem::fromCategory($category), $result['items']),
            totalItems: $result['totalItems'],
            totalPages: $result['totalPages'],
        );
    }
}
