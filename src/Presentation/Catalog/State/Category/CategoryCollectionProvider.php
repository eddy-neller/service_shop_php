<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\State\Category;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\UseCase\Query\DisplayListCategory\DisplayListCategoryQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Shared\State\CollectionParameterNormalizerTrait;
use Symfony\Component\HttpFoundation\Request;

final readonly class CategoryCollectionProvider implements ProviderInterface
{
    use CollectionParameterNormalizerTrait;

    public function __construct(
        private QueryBusInterface $queryBus,
        private CategoryResourcePresenter $categoryResourcePresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $filters = $context['filters'] ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        $orderBy = $this->normalizeOrderBy($filters['order'] ?? null, CategoryRepositoryInterface::SORT_FIELDS);

        $output = $this->queryBus->dispatch(new DisplayListCategoryQuery(
            page: $this->normalizePaginationParameter($filters['page'] ?? null),
            itemsPerPage: $this->normalizePaginationParameter($filters['itemsPerPage'] ?? null),
            filters: $filters,
            orderBy: $orderBy,
        ));

        $request = $context['request'] ?? null;
        if ($request instanceof Request) {
            $request->attributes->set('_total_items', $output->totalItems);
            $request->attributes->set('_total_pages', $output->totalPages);
        }

        $items = [];
        foreach ($output->items as $category) {
            $items[] = $this->categoryResourcePresenter->toSummaryResource($category);
        }

        return $items;
    }
}
