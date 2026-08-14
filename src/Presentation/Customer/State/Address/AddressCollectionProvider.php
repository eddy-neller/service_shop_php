<?php

declare(strict_types=1);

namespace App\Presentation\Customer\State\Address;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Customer\Port\CustomerRepositoryInterface;
use App\Application\Customer\UseCase\Query\DisplayListAddress\DisplayListAddressQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Customer\Presenter\AddressResourcePresenter;
use App\Presentation\Shared\State\CollectionParameterNormalizerTrait;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use Symfony\Component\HttpFoundation\Request;

final readonly class AddressCollectionProvider implements ProviderInterface
{
    use CollectionParameterNormalizerTrait;

    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentCustomerResolver $customerResolver,
        private AddressResourcePresenter $presenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $filters = $context['filters'] ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        $orderBy = $this->normalizeOrderBy($filters['order'] ?? null, CustomerRepositoryInterface::ADDRESS_SORT_FIELDS);

        $output = $this->queryBus->dispatch(new DisplayListAddressQuery(
            ownerId: $this->customerResolver->resolve(),
            page: $this->normalizePaginationParameter($filters['page'] ?? null),
            itemsPerPage: $this->normalizePaginationParameter($filters['itemsPerPage'] ?? null),
            orderBy: $orderBy,
            filters: $filters,
        ));

        $request = $context['request'] ?? null;
        if ($request instanceof Request) {
            $request->attributes->set('_total_items', $output->totalItems);
            $request->attributes->set('_total_pages', $output->totalPages);
        }

        $items = [];
        foreach ($output->items as $address) {
            $items[] = $this->presenter->toResource($address);
        }

        return $items;
    }
}
