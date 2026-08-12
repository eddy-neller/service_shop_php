<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Catalog\Category;

use ApiPlatform\Metadata\Operation;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Catalog\UseCase\Query\DisplayCategory\DisplayCategoryQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use App\Presentation\Catalog\ApiResource\CategoryResource;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Catalog\State\Category\CategoryGetProvider;
use App\Presentation\Shared\State\PresentationErrorCode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CategoryGetProviderTest extends TestCase
{
    private QueryBusInterface&MockObject $queryBus;

    private Operation&MockObject $operation;

    private CategoryGetProvider $provider;

    protected function setUp(): void
    {
        $this->queryBus = $this->createMock(QueryBusInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())
            ->method('getName');
        $presenter = new CategoryResourcePresenter();

        $this->provider = new CategoryGetProvider($this->queryBus, $presenter);
    }

    public function testProvideWithValidId(): void
    {
        $categoryId = '550e8400-e29b-41d4-a716-446655440000';
        $output = $this->createCategoryTree();

        $this->queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($categoryId, $output): CategoryItem {
                $this->assertInstanceOf(DisplayCategoryQuery::class, $query);
                $this->assertSame($categoryId, $query->categoryId);

                return $output;
            });

        $result = $this->provider->provide($this->operation, ['id' => $categoryId]);

        $this->assertInstanceOf(CategoryResource::class, $result);
        $this->assertSame('Category title', $result->title);
        $this->assertFalse($result->hasChildren);
    }

    public function testProvideThrowsLogicExceptionWhenIdIsMissing(): void
    {
        $this->queryBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->provider->provide($this->operation, []);
    }

    public function testProvideThrowsLogicExceptionWhenIdIsNotString(): void
    {
        $this->queryBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->provider->provide($this->operation, ['id' => 123]);
    }

    private function createCategoryTree(): CategoryItem
    {
        $now = new DateTimeImmutable('2024-01-01 10:00:00');
        $category = Category::create(
            id: CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            title: CategoryTitle::fromString('Category title'),
            slug: Slug::fromString('category-title'),
            now: $now,
        );

        return CategoryItem::fromCategory($category, null, []);
    }
}
