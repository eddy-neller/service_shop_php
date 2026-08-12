<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\State\Category;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Application\Catalog\UseCase\Query\DisplayCategory\DisplayCategoryQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Presentation\Catalog\ApiResource\CategoryResource;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class CategoryGetProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CategoryResourcePresenter $categoryResourcePresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CategoryResource
    {
        $categoryId = $uriVariables['id'] ?? null;

        if (!is_string($categoryId) || '' === $categoryId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $output = $this->queryBus->dispatch(new DisplayCategoryQuery($categoryId));

        return $this->categoryResourcePresenter->toResource($output);
    }
}
