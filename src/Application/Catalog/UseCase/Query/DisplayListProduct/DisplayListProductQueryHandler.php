<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Query\DisplayListProduct;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Catalog\ReadModel\Catalog\ProductList;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use App\Application\Shared\ReadModel\Pagination;

final readonly class DisplayListProductQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $repository,
    ) {
    }

    public function handle(DisplayListProductQuery $query): ProductList
    {
        $orderBy = [] !== $query->orderBy ? $query->orderBy : ['createdAt' => 'DESC'];
        $pagination = Pagination::fromRaw($query->page, $query->itemsPerPage);

        $result = $this->repository->list(
            filters: $query->filters,
            orderBy: $orderBy,
            page: $pagination->page,
            itemsPerPage: $pagination->itemsPerPage,
        );

        return new ProductList(
            items: array_map(
                static fn (array $item): ProductItem => ProductItem::fromProduct($item['product'], $item['category']),
                $result['items'],
            ),
            totalItems: $result['totalItems'],
            totalPages: $result['totalPages'],
        );
    }
}
