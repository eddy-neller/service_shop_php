<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\CreateCategoryByAdmin;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\SlugGeneratorInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\CategoryTitleAlreadyUsedException;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryDescription;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;

final readonly class CreateCategoryByAdminCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CategoryRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private SlugGeneratorInterface $slugGenerator,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(CreateCategoryByAdminCommand $command): CategoryItem
    {
        $id = $this->repository->nextIdentity();
        $title = CategoryTitle::fromString($command->title);
        $description = CategoryDescription::fromNullableString($command->description);
        $parentId = null !== $command->parentId ? CategoryId::fromString($command->parentId) : null;
        $slug = $this->slugGenerator->generate($title->toString());

        $this->transactional->transactional(function () use ($id, $title, $description, $parentId, $slug): void {
            if (null !== $this->repository->findByTitle($title)) {
                throw new CategoryTitleAlreadyUsedException();
            }

            if (null !== $parentId) {
                $parent = $this->repository->findById($parentId);
                if (null === $parent) {
                    throw new CategoryNotFoundException('Parent category not found.');
                }
            }

            $category = Category::create(
                id: $id,
                title: $title,
                slug: $slug,
                now: $this->clock->now(),
                parentId: $parentId,
                description: $description,
            );

            $this->repository->save($category);
            $this->eventBus->publishAll($category->releaseEvents());
        });

        $categoryTree = $this->repository->findTreeById($id);
        if (null === $categoryTree) {
            throw new CategoryNotFoundException();
        }

        return CategoryItem::fromCategory(
            category: $categoryTree['category'],
            parent: $categoryTree['parent'],
            children: $categoryTree['children'],
        );
    }
}
