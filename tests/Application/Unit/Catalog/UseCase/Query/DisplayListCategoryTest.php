<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Query;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Catalog\UseCase\Query\DisplayListCategory\DisplayListCategoryQuery;
use App\Application\Catalog\UseCase\Query\DisplayListCategory\DisplayListCategoryQueryHandler;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisplayListCategoryTest extends TestCase
{
    private CategoryRepositoryInterface&MockObject $repository;

    private DisplayListCategoryQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CategoryRepositoryInterface::class);
        $this->handler = new DisplayListCategoryQueryHandler($this->repository);
    }

    public function testHandleReturnsCategoriesAndPagination(): void
    {
        $query = new DisplayListCategoryQuery(
            page: '2',
            itemsPerPage: '5',
            filters: ['level' => 1],
            orderBy: ['title' => 'ASC'],
        );

        $category = $this->createCategory(CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000'));
        $categoryItem = CategoryItem::fromCategory($category);

        $this->repository->expects($this->once())
            ->method('list')
            ->with(['level' => 1], ['title' => 'ASC'], 2, 5)
            ->willReturn(['items' => [$category], 'totalItems' => 10, 'totalPages' => 2]);

        $output = $this->handler->handle($query);

        $this->assertEquals([$categoryItem], $output->items);
        $this->assertSame(10, $output->totalItems);
        $this->assertSame(2, $output->totalPages);
    }

    public function testHandleAppliesDefaultsWhenValuesAreInvalid(): void
    {
        $query = new DisplayListCategoryQuery(
            page: '0',
            itemsPerPage: '0',
            filters: [],
            orderBy: [],
        );

        $category = $this->createCategory(CategoryId::fromString('550e8400-e29b-41d4-a716-446655440001'));
        $categoryItem = CategoryItem::fromCategory($category);

        $this->repository->expects($this->once())
            ->method('list')
            ->with([], ['createdAt' => 'DESC'], 1, 30)
            ->willReturn(['items' => [$category], 'totalItems' => 1, 'totalPages' => 1]);

        $output = $this->handler->handle($query);

        $this->assertEquals([$categoryItem], $output->items);
    }

    public function testQueryCacheKeyIsStableWhenFiltersAndOrderByAreReordered(): void
    {
        $this->repository->expects($this->never())->method('list');

        $queryA = new DisplayListCategoryQuery(
            page: '2',
            itemsPerPage: '5',
            filters: ['status' => 1, 'level' => 2],
            orderBy: ['title' => 'ASC', 'createdAt' => 'DESC'],
        );
        $queryB = new DisplayListCategoryQuery(
            page: '2',
            itemsPerPage: '5',
            filters: ['level' => 2, 'status' => 1],
            orderBy: ['createdAt' => 'DESC', 'title' => 'ASC'],
        );

        $this->assertSame($queryA->cacheKey(), $queryB->cacheKey());
    }

    public function testQueryCacheMetadata(): void
    {
        $this->repository->expects($this->never())->method('list');

        $query = new DisplayListCategoryQuery(
            page: '1',
            itemsPerPage: '10',
        );

        $this->assertSame(3600, $query->cacheTtl());
        $this->assertSame(['categories-collection'], $query->cacheTags());
    }

    private function createCategory(CategoryId $categoryId): Category
    {
        return Category::reconstitute(
            id: $categoryId,
            title: CategoryTitle::fromString('Category title'),
            slug: Slug::fromString('category-title'),
            createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2025-01-02 10:00:00'),
            productCount: 0,
            level: 1,
        );
    }
}
