<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Query;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Catalog\UseCase\Query\DisplayCategory\DisplayCategoryQuery;
use App\Application\Catalog\UseCase\Query\DisplayCategory\DisplayCategoryQueryHandler;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisplayCategoryTest extends TestCase
{
    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440000';

    private CategoryRepositoryInterface&MockObject $repository;

    private DisplayCategoryQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CategoryRepositoryInterface::class);
        $this->handler = new DisplayCategoryQueryHandler($this->repository);
    }

    public function testHandleReturnsCategoryTreeWhenFound(): void
    {
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $query = new DisplayCategoryQuery($categoryId->toString());
        $category = $this->createCategory($categoryId);
        $categoryItem = CategoryItem::fromCategory($category, null, []);

        $this->repository->expects($this->once())
            ->method('findTreeById')
            ->with($categoryId)
            ->willReturn(['category' => $category, 'parent' => null, 'children' => []]);

        $output = $this->handler->handle($query);

        $this->assertEquals($categoryItem, $output);
    }

    public function testHandleThrowsWhenCategoryNotFound(): void
    {
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $query = new DisplayCategoryQuery($categoryId->toString());

        $this->repository->expects($this->once())
            ->method('findTreeById')
            ->with($categoryId)
            ->willReturn(null);

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Category not found.');

        $this->handler->handle($query);
    }

    public function testQueryCacheMetadata(): void
    {
        $this->repository->expects($this->never())->method('findTreeById');

        $query = new DisplayCategoryQuery(self::CATEGORY_ID);

        $this->assertSame('category-item-' . self::CATEGORY_ID, $query->cacheKey());
        $this->assertSame(3600, $query->cacheTtl());
        $this->assertSame(['categories-collection', 'products-collection'], $query->cacheTags());
    }

    private function createCategory(CategoryId $id): Category
    {
        return Category::create(
            id: $id,
            title: CategoryTitle::fromString('My category'),
            slug: Slug::fromString('my-category'),
            now: new DateTimeImmutable('2024-01-01 09:00:00'),
        );
    }
}
