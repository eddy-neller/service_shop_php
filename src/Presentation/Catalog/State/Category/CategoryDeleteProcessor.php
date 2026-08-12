<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\State\Category;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Catalog\UseCase\Command\DeleteCategoryByAdmin\DeleteCategoryByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class CategoryDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $categoryId = $uriVariables['id'] ?? null;

        if (!is_string($categoryId) || '' === $categoryId) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $this->commandBus->dispatch(new DeleteCategoryByAdminCommand($categoryId));
    }
}
