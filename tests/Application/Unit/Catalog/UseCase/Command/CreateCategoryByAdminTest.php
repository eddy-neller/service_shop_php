<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Command;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Catalog\UseCase\Command\CreateCategoryByAdmin\CreateCategoryByAdminCommand;
use App\Application\Catalog\UseCase\Command\CreateCategoryByAdmin\CreateCategoryByAdminCommandHandler;
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
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CreateCategoryByAdminTest extends TestCase
{
    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string PARENT_ID = '550e8400-e29b-41d4-a716-446655440001';

    private CategoryRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    private SlugGeneratorInterface&MockObject $slugGenerator;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private CreateCategoryByAdminCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CategoryRepositoryInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);
        $this->slugGenerator = $this->createMock(SlugGeneratorInterface::class);
        $this->publishedEvents = [];
        $eventBus = $this->createStub(DomainEventBusInterface::class);
        $eventBus->method('publishAll')->willReturnCallback(function (array $events): void {
            $this->publishedEvents = [...$this->publishedEvents, ...$events];
        });
        $this->handler = new CreateCategoryByAdminCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $this->slugGenerator,
            $eventBus,
        );
    }

    public function testHandleCreatesCategoryWithParentAndDescription(): void
    {
        $now = new DateTimeImmutable('2024-01-01 10:00:00');
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $parentId = CategoryId::fromString(self::PARENT_ID);
        $title = 'My category';
        $descriptionValue = 'Category description';
        $description = CategoryDescription::fromString($descriptionValue);
        $slug = Slug::fromString('my-category');
        $parent = $this->createCategory($parentId, 'Parent category', 'parent-category');
        $category = $this->createCategory($categoryId, $title, $slug->toString(), $description, $parentId);
        $categoryItem = CategoryItem::fromCategory(
            category: $category,
            parent: $parent,
            children: [],
        );

        $command = new CreateCategoryByAdminCommand(
            title: $title,
            description: $descriptionValue,
            parentId: $parentId->toString(),
        );

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->repository->expects($this->once())
            ->method('nextIdentity')
            ->willReturn($categoryId);

        $this->repository->expects($this->once())
            ->method('findByTitle')
            ->with(CategoryTitle::fromString($title))
            ->willReturn(null);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with($title)
            ->willReturn($slug);

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($parentId)
            ->willReturn($parent);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Category $category) use ($categoryId, $title, $description, $slug, $parentId, $now): bool {
                return $category->getId()->equals($categoryId)
                    && $category->getTitle()->toString() === $title
                    && $category->getSlug()->equals($slug)
                    && $category->getDescription()?->equals($description)
                    && $category->getParentId()?->equals($parentId)
                    && $category->getCreatedAt() === $now
                    && $category->getUpdatedAt() === $now
                    && 0 === $category->getProductCount()
                    && 0 === $category->getLevel();
            }));

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

        $this->assertEquals($categoryItem, $output);
    }

    public function testHandleThrowsWhenParentNotFound(): void
    {
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $parentId = CategoryId::fromString(self::PARENT_ID);

        $command = new CreateCategoryByAdminCommand(
            title: 'My category',
            description: null,
            parentId: $parentId->toString(),
        );

        $this->clock->expects($this->never())
            ->method('now');

        $this->repository->expects($this->once())
            ->method('nextIdentity')
            ->willReturn($categoryId);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('My category')
            ->willReturn(Slug::fromString('my-category'));

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($parentId)
            ->willReturn(null);

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

    public function testHandleThrowsWhenCategoryTreeIsMissing(): void
    {
        $now = new DateTimeImmutable('2024-01-01 10:00:00');
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);

        $command = new CreateCategoryByAdminCommand(
            title: 'My category',
            description: null,
            parentId: null,
        );

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->repository->expects($this->once())
            ->method('nextIdentity')
            ->willReturn($categoryId);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('My category')
            ->willReturn(Slug::fromString('my-category'));

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Category::class));

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

    public function testHandleThrowsWhenTitleAlreadyUsed(): void
    {
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $title = 'Existing category';

        $command = new CreateCategoryByAdminCommand(
            title: $title,
            description: null,
            parentId: null,
        );

        $this->clock->expects($this->never())
            ->method('now');

        $this->repository->expects($this->once())
            ->method('nextIdentity')
            ->willReturn($categoryId);

        $this->repository->expects($this->once())
            ->method('findByTitle')
            ->with(CategoryTitle::fromString($title))
            ->willReturn($this->createCategory(CategoryId::fromString(self::PARENT_ID), $title, 'existing-category'));

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with($title)
            ->willReturn(Slug::fromString('existing-category'));

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

    private function createCategory(
        CategoryId $id,
        string $title,
        string $slug,
        ?CategoryDescription $description = null,
        ?CategoryId $parentId = null,
    ): Category {
        return Category::create(
            id: $id,
            title: CategoryTitle::fromString($title),
            slug: Slug::fromString($slug),
            now: new DateTimeImmutable('2024-01-01 09:00:00'),
            parentId: $parentId,
            description: $description,
        );
    }
}
