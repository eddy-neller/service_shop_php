<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Catalog\Category;

use ApiPlatform\Metadata\GetCollection;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Catalog\ReadModel\Catalog\CategoryList;
use App\Application\Catalog\UseCase\Query\DisplayListCategory\DisplayListCategoryQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Catalog\Model\Category as DomainCategory;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use App\Presentation\Catalog\ApiResource\CategoryResource;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Catalog\State\Category\CategoryCollectionProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CategoryCollectionProviderTest extends TestCase
{
    public function testItMapsCategoriesToResourcesAndSetsPagination(): void
    {
        $request = new Request();
        $queryBus = $this->createMock(QueryBusInterface::class);
        $category = $this->createCategory();
        $output = new CategoryList([CategoryItem::fromCategory($category)], 3, 2);

        $queryBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($output): CategoryList {
                $this->assertInstanceOf(DisplayListCategoryQuery::class, $query);
                $this->assertSame('2', $query->page);
                $this->assertSame('15', $query->itemsPerPage);
                $this->assertSame('1', $query->filters['level'] ?? null);
                $this->assertSame(['title' => 'ASC'], $query->orderBy);

                return $output;
            });

        $provider = new CategoryCollectionProvider($queryBus, new CategoryResourcePresenter());

        $result = $provider->provide(
            new GetCollection(name: 'shop-categories-col'),
            context: [
                'request' => $request,
                'filters' => [
                    'page' => '2',
                    'itemsPerPage' => '15',
                    'level' => '1',
                    'order' => [
                        'title' => 'asc',
                    ],
                ],
            ],
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CategoryResource::class, $result[0]);
        $this->assertSame('Category title', $result[0]->title);
        $this->assertSame(1, $result[0]->level);
        $this->assertFalse($result[0]->hasChildren);
        $this->assertSame(3, $request->attributes->get('_total_items'));
        $this->assertSame(2, $request->attributes->get('_total_pages'));
    }

    public function testItHandlesInvalidFiltersWithoutRequest(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);
        $category = $this->createCategory();
        $output = new CategoryList([CategoryItem::fromCategory($category)], 1, 1);

        $queryBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($output): CategoryList {
                $this->assertInstanceOf(DisplayListCategoryQuery::class, $query);
                $this->assertNull($query->page);
                $this->assertNull($query->itemsPerPage);
                $this->assertSame([], $query->filters);
                $this->assertSame([], $query->orderBy);

                return $output;
            });

        $provider = new CategoryCollectionProvider($queryBus, new CategoryResourcePresenter());

        $result = $provider->provide(
            new GetCollection(name: 'shop-categories-col'),
            context: [
                'filters' => 'not-an-array',
            ],
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CategoryResource::class, $result[0]);
    }

    private function createCategory(): DomainCategory
    {
        return DomainCategory::reconstitute(
            id: CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            title: CategoryTitle::fromString('Category title'),
            slug: Slug::fromString('category-title'),
            createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2025-02-01 10:00:00'),
            productCount: 0,
            level: 1,
        );
    }
}
