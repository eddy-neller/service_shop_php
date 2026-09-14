<?php

declare(strict_types=1);

namespace App\Presentation\Customer\State\Customer;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Query\DisplayListCustomer\DisplayListCustomerQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Customer\Presenter\CustomerResourcePresenter;
use App\Presentation\Shared\State\CollectionParameterNormalizerTrait;
use Symfony\Component\HttpFoundation\Request;

final readonly class CustomerCollectionProvider implements ProviderInterface
{
    use CollectionParameterNormalizerTrait;

    public function __construct(
        private QueryBusInterface $queryBus,
        private CustomerResourcePresenter $customerResourcePresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $filters = $context['filters'] ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        $query = new DisplayListCustomerQuery(
            page: $this->normalizePaginationParameter($filters['page'] ?? null),
            itemsPerPage: $this->normalizePaginationParameter($filters['itemsPerPage'] ?? null),
            filters: $filters,
            orderBy: $this->normalizeOrderBy($filters['order'] ?? null, CustomerRepositoryInterface::SORT_FIELDS),
        );

        $output = $this->queryBus->dispatch($query);

        $request = $context['request'] ?? null;
        if ($request instanceof Request) {
            $request->attributes->set('_total_items', $output->totalItems);
            $request->attributes->set('_total_pages', $output->totalPages);
        }

        $items = [];
        foreach ($output->items as $customer) {
            $items[] = $this->customerResourcePresenter->toSummaryResource($customer);
        }

        return $items;
    }
}
