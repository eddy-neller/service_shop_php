<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Command;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\UseCase\Command\UpdateCategoryByAdmin\UpdateCategoryByAdminCommand;
use App\Application\Catalog\UseCase\Command\UpdateCategoryByAdmin\UpdateCategoryByAdminCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\SlugGeneratorInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CatalogDomainException;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\CategoryTitleAlreadyUsedException;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryDescription;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UpdateCategoryByAdminTest extends TestCase
{
    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string PARENT_ID = '550e8400-e29b-41d4-a716-446655440001';

    private CategoryRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    private SlugGeneratorInterface&MockObject $slugGenerator;

    private UpdateCategoryByAdminCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CategoryRepositoryInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);
        $this->slugGenerator = $this->createMock(SlugGeneratorInterface::class);
        $this->handler = new UpdateCategoryByAdminCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $this->slugGenerator,
        );
    }

    public function testHandleUpdatesAllFields(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $parentId = CategoryId::fromString(self::PARENT_ID);
        $category = $this->createCategory($categoryId, 'Old title', 'old-title');
        $parent = $this->createCategory($parentId, 'Parent', 'parent');
        $slug = Slug::fromString('new-title');

        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId->toString(),
            title: 'New title',
            description: 'New description',
            parentId: $parentId->toString(),
        );

        $this->repository->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function (CategoryId $id) use ($categoryId, $parentId, $category, $parent): ?Category {
                if ($id->equals($categoryId)) {
                    return $category;
                }

                if ($id->equals($parentId)) {
                    return $parent;
                }

                return null;
            });

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->repository->expects($this->once())
            ->method('findByTitle')
            ->with(CategoryTitle::fromString('New title'))
            ->willReturn(null);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('New title')
            ->willReturn($slug);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($category);

        $this->repository->expects($this->once())
            ->method('findTreeById')
            ->with($categoryId)
            ->willReturn(['category' => $category, 'parent' => $parent, 'children' => []]);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $output = $this->handler->handle($command);

        $this->assertSame('New title', $output->title);
        $this->assertSame('New title', $category->getTitle()->toString());
        $this->assertSame('new-title', $category->getSlug()->toString());
        $this->assertSame('New description', $category->getDescription()?->toString());
        $this->assertTrue($category->getParentId()?->equals($parentId));
        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testHandleUpdatesOnlyProvidedFields(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $description = CategoryDescription::fromString('Existing description');
        $category = $this->createCategory($categoryId, 'Old title', 'old-title', $description);
        $slug = Slug::fromString('new-title');

        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId->toString(),
            title: 'New title',
            description: null,
            parentId: null,
        );

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('New title')
            ->willReturn($slug);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($category);

        $this->repository->expects($this->once())
            ->method('findTreeById')
            ->with($categoryId)
            ->willReturn(['category' => $category, 'parent' => null, 'children' => []]);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());

        $output = $this->handler->handle($command);

        $this->assertSame('New title', $output->title);
        $this->assertSame('New title', $category->getTitle()->toString());
        $this->assertSame('new-title', $category->getSlug()->toString());
        $this->assertSame($description, $category->getDescription());
    }

    public function testHandleThrowsWhenCategoryNotFound(): void
    {
        $this->clock->expects($this->never())
            ->method('now');
        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->slugGenerator->expects($this->never())
            ->method('generate');

        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId->toString(),
            title: null,
            description: null,
            parentId: null,
        );

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn(null);

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Category not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenParentIsSelf(): void
    {
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);

        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId->toString(),
            title: null,
            description: null,
            parentId: $categoryId->toString(),
        );

        $this->repository->expects($this->never())
            ->method('findById');

        $this->clock->expects($this->never())
            ->method('now');

        $this->slugGenerator->expects($this->never())
            ->method('generate');

        $this->repository->expects($this->never())
            ->method('save');

        $this->transactional->expects($this->never())
            ->method('transactional');

        $this->expectException(CatalogDomainException::class);
        $this->expectExceptionMessage('Category cannot be its own parent.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenParentNotFound(): void
    {
        $this->slugGenerator->expects($this->never())
            ->method('generate');

        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $parentId = CategoryId::fromString(self::PARENT_ID);
        $category = $this->createCategory($categoryId, 'Old title', 'old-title');

        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId->toString(),
            title: null,
            description: null,
            parentId: $parentId->toString(),
        );

        $this->repository->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function (CategoryId $id) use ($categoryId, $parentId, $category): ?Category {
                if ($id->equals($categoryId)) {
                    return $category;
                }

                if ($id->equals($parentId)) {
                    return null;
                }

                return null;
            });

        $this->clock->expects($this->never())
            ->method('now');

        $this->repository->expects($this->never())
            ->method('save');

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Parent category not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenCategoryTreeMissing(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $category = $this->createCategory($categoryId, 'Old title', 'old-title');

        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId->toString(),
            title: 'New title',
            description: null,
            parentId: null,
        );

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('New title')
            ->willReturn(Slug::fromString('new-title'));

        $this->repository->expects($this->once())
            ->method('save')
            ->with($category);

        $this->repository->expects($this->once())
            ->method('findTreeById')
            ->with($categoryId)
            ->willReturn(null);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Category not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenTitleBelongsToAnotherCategory(): void
    {
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $otherId = CategoryId::fromString(self::PARENT_ID);
        $category = $this->createCategory($categoryId, 'Old title', 'old-title');
        $other = $this->createCategory($otherId, 'New title', 'new-title');

        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId->toString(),
            title: 'New title',
            description: null,
            parentId: null,
        );

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->clock->expects($this->never())
            ->method('now');

        $this->repository->expects($this->once())
            ->method('findByTitle')
            ->with(CategoryTitle::fromString('New title'))
            ->willReturn($other);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('New title')
            ->willReturn(Slug::fromString('new-title'));

        $this->repository->expects($this->never())
            ->method('save');

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $this->expectException(CategoryTitleAlreadyUsedException::class);

        $this->handler->handle($command);
    }

    public function testHandleSucceedsWhenTitleBelongsToSameCategory(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $category = $this->createCategory($categoryId, 'Old title', 'old-title');

        $command = new UpdateCategoryByAdminCommand(
            categoryId: $categoryId->toString(),
            title: 'Same title',
            description: null,
            parentId: null,
        );

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->repository->expects($this->once())
            ->method('findByTitle')
            ->with(CategoryTitle::fromString('Same title'))
            ->willReturn($category);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('Same title')
            ->willReturn(Slug::fromString('same-title'));

        $this->repository->expects($this->once())
            ->method('save')
            ->with($category);

        $this->repository->expects($this->once())
            ->method('findTreeById')
            ->with($categoryId)
            ->willReturn(['category' => $category, 'parent' => null, 'children' => []]);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $output = $this->handler->handle($command);

        $this->assertSame('Same title', $output->title);
        $this->assertSame('Same title', $category->getTitle()->toString());
    }

    private function createCategory(
        CategoryId $id,
        string $title,
        string $slug,
        ?CategoryDescription $description = null,
    ): Category {
        return Category::create(
            id: $id,
            title: CategoryTitle::fromString($title),
            slug: Slug::fromString($slug),
            now: new DateTimeImmutable('2024-01-01 09:00:00'),
            description: $description,
        );
    }
}
