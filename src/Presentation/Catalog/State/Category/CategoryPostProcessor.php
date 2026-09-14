<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\State\Category;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Catalog\UseCase\Command\CreateCategoryByAdmin\CreateCategoryByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Catalog\ApiResource\CategoryResource;
use App\Presentation\Catalog\Dto\Category\CategoryPostInput;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class CategoryPostProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CategoryResourcePresenter $categoryResourcePresenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CategoryResource
    {
        if (!$data instanceof CategoryPostInput) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $parentId = null;
        if (null !== $data->parent) {
            $parentId = $data->parent->id;
        }

        $command = new CreateCategoryByAdminCommand(
            title: $data->title,
            description: $data->description,
            parentId: $parentId,
        );

        $output = $this->commandBus->dispatch($command);

        return $this->categoryResourcePresenter->toResource($output);
    }
}
