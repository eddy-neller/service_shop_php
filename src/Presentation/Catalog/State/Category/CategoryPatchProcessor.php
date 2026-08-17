<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\State\Category;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Catalog\UseCase\Command\UpdateCategoryByAdmin\UpdateCategoryByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Catalog\ApiResource\CategoryResource;
use App\Presentation\Catalog\Dto\Category\CategoryPatchInput;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;
use ReflectionProperty;

final readonly class CategoryPatchProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CategoryResourcePresenter $categoryResourcePresenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CategoryResource
    {
        if (!$data instanceof CategoryPatchInput) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $categoryId = $uriVariables['id'] ?? null;

        if (!is_string($categoryId) || '' === $categoryId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $parentProvided = (new ReflectionProperty($data, 'parent'))->isInitialized($data);
        $parentId = null;
        if ($parentProvided && null !== $data->parent) {
            $parentId = $data->parent->id;
        }

        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId,
            title: $data->title,
            description: $data->description,
            parentId: $parentId,
            parentProvided: $parentProvided,
        );

        $output = $this->commandBus->dispatch($command);

        return $this->categoryResourcePresenter->toResource($output);
    }
}
