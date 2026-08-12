<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\State\Product;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Catalog\UseCase\Query\DisplayProduct\DisplayProductQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Catalog\ApiResource\ProductResource;
use App\Presentation\Catalog\Presenter\ProductResourcePresenter;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class ProductGetProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private ProductResourcePresenter $productResourcePresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $productId = $uriVariables['id'] ?? null;

        if (!is_string($productId) || '' === $productId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $output = $this->queryBus->dispatch(new DisplayProductQuery($productId));

        return $this->productResourcePresenter->toResource($output);
    }
}
