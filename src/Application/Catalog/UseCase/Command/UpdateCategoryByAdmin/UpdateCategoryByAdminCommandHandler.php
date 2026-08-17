<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\UpdateCategoryByAdmin;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\SlugGeneratorInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CatalogDomainException;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\CategoryTitleAlreadyUsedException;
use App\Domain\Catalog\ValueObject\CategoryDescription;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;

final readonly class UpdateCategoryByAdminCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CategoryRepositoryInterface $repository,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private SlugGeneratorInterface $slugGenerator,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(UpdateCategoryByAdminCommand $command): CategoryItem
    {
        $categoryId = CategoryId::fromString($command->categoryId);
        $parentId = $command->parentProvided && null !== $command->parentId
            ? CategoryId::fromString($command->parentId)
            : null;
        $title = null !== $command->title ? CategoryTitle::fromString($command->title) : null;
        $slug = null !== $title ? $this->slugGenerator->generate($title->toString()) : null;
        $description = null !== $command->description
            ? CategoryDescription::fromString($command->description)
            : null;

        if (null !== $parentId && $categoryId->equals($parentId)) {
            throw new CatalogDomainException('Category cannot be its own parent.');
        }

        $this->transactional->transactional(
            function () use ($categoryId, $parentId, $command, $title, $slug, $description): void {
                $this->updateCategory($categoryId, $parentId, $command->parentProvided, $title, $slug, $description);
            },
        );

        $categoryTree = $this->repository->findTreeById($categoryId);
        if (null === $categoryTree) {
            throw new CategoryNotFoundException();
        }

        return CategoryItem::fromCategory(
            category: $categoryTree['category'],
            parent: $categoryTree['parent'],
            children: $categoryTree['children'],
        );
    }

    private function updateCategory(
        CategoryId $categoryId,
        ?CategoryId $parentId,
        bool $parentProvided,
        ?CategoryTitle $title,
        ?Slug $slug,
        ?CategoryDescription $description,
    ): void {
        $category = $this->repository->findById($categoryId);

        if (null === $category) {
            throw new CategoryNotFoundException();
        }

        if (null !== $title) {
            $existing = $this->repository->findByTitle($title);
            if (null !== $existing && !$existing->getId()->equals($category->getId())) {
                throw new CategoryTitleAlreadyUsedException();
            }
        }

        if ($parentProvided && null !== $parentId) {
            $parent = $this->repository->findById($parentId);
            if (null === $parent) {
                throw new CategoryNotFoundException('Parent category not found.');
            }

            if ($this->repository->isDescendantOf($parentId, $categoryId)) {
                throw new CatalogDomainException('Category cannot be moved below one of its descendants.');
            }
        }

        $now = $this->clock->now();

        if (null !== $title && null !== $slug) {
            $category->rename($title, $slug, $now);
        }

        if (null !== $description) {
            $category->describe($description, $now);
        }

        if ($parentProvided) {
            $category->moveTo($parentId, $now);
        }

        $this->repository->save($category);

        // Un PATCH qui touche titre, description et parent publie trois faits distincts :
        // ce sont trois decisions, meme si l'appelant les a groupees dans une requete.
        $this->eventBus->publishAll($category->releaseEvents());
    }
}
