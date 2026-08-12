<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\DeleteCategoryByAdmin;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\ValueObject\CategoryId;

final readonly class DeleteCategoryByAdminCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CategoryRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
    ) {
    }

    public function handle(DeleteCategoryByAdminCommand $command): void
    {
        $categoryId = CategoryId::fromString($command->categoryId);

        $this->transactional->transactional(function () use ($categoryId): void {
            $category = $this->repository->findById($categoryId);

            if (null === $category) {
                throw new CategoryNotFoundException();
            }

            $category->delete($this->clock->now());

            $this->repository->delete($category);
        });
    }
}
