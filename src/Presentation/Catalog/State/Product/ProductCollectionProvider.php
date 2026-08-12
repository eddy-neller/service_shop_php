<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\State\Product;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\UseCase\Query\DisplayListProduct\DisplayListProductQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Catalog\Presenter\ProductResourcePresenter;
use App\Presentation\Shared\State\CollectionParameterNormalizerTrait;
use Symfony\Component\HttpFoundation\Request;

final readonly class ProductCollectionProvider implements ProviderInterface
{
    use CollectionParameterNormalizerTrait;

    public function __construct(
        private QueryBusInterface $queryBus,
        private ProductResourcePresenter $productResourcePresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $filters = $context['filters'] ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        $orderBy = $this->normalizeOrderBy($filters['order'] ?? null, ProductRepositoryInterface::SORT_FIELDS);

        $output = $this->queryBus->dispatch(new DisplayListProductQuery(
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
        foreach ($output->items as $product) {
            $items[] = $this->productResourcePresenter->toResource($product);
        }

        return $items;
    }
}
