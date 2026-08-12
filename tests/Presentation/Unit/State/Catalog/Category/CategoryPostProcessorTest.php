<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Catalog\Category;

use ApiPlatform\Metadata\Operation;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Catalog\UseCase\Command\CreateCategoryByAdmin\CreateCategoryByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use App\Presentation\Catalog\ApiResource\CategoryResource;
use App\Presentation\Catalog\Dto\Category\CategoryPostInput;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Catalog\State\Category\CategoryPostProcessor;
use App\Presentation\Shared\State\PresentationErrorCode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CategoryPostProcessorTest extends TestCase
{
    private CommandBusInterface&MockObject $commandBus;

    private Operation&MockObject $operation;

    private CategoryPostProcessor $processor;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())
            ->method('getName');
        $presenter = new CategoryResourcePresenter();

        $this->processor = new CategoryPostProcessor(
            $this->commandBus,
            $presenter,
        );
    }

    public function testProcessWithValidInput(): void
    {
        $input = new CategoryPostInput();
        $input->title = 'New category';
        $input->description = 'Category description';
        $input->parent = $this->createCategoryResource('550e8400-e29b-41d4-a716-446655440001');

        $output = $this->createCategoryTree();

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($input, $output): CategoryItem {
                $this->assertInstanceOf(CreateCategoryByAdminCommand::class, $command);
                $this->assertSame($input->title, $command->title);
                $this->assertSame($input->description, $command->description);
                $this->assertSame($input->parent->id, $command->parentId);

                return $output;
            });

        $result = $this->processor->process($input, $this->operation);

        $this->assertInstanceOf(CategoryResource::class, $result);
        $this->assertSame('New category', $result->title);
    }

    public function testProcessThrowsLogicExceptionForInvalidInput(): void
    {
        $invalidInput = new stdClass();

        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process($invalidInput, $this->operation);
    }

    private function createCategoryTree(): CategoryItem
    {
        $categoryId = CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $parentId = CategoryId::fromString('550e8400-e29b-41d4-a716-446655440001');
        $now = new DateTimeImmutable('2024-01-01 10:00:00');

        $category = Category::create(
            id: $categoryId,
            title: CategoryTitle::fromString('New category'),
            slug: Slug::fromString('new-category'),
            now: $now,
            parentId: $parentId,
        );

        $parent = Category::create(
            id: $parentId,
            title: CategoryTitle::fromString('Parent category'),
            slug: Slug::fromString('parent-category'),
            now: $now,
        );

        return CategoryItem::fromCategory($category, $parent, []);
    }

    private function createCategoryResource(string $id): CategoryResource
    {
        $resource = new CategoryResource();
        $resource->id = $id;

        return $resource;
    }
}
