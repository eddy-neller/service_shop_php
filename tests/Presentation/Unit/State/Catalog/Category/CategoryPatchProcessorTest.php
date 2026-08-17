<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Catalog\Category;

use ApiPlatform\Metadata\Operation;
use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Application\Catalog\UseCase\Command\UpdateCategoryByAdmin\UpdateCategoryByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use App\Presentation\Catalog\ApiResource\CategoryResource;
use App\Presentation\Catalog\Dto\Category\CategoryPatchInput;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Catalog\State\Category\CategoryPatchProcessor;
use App\Presentation\Shared\State\PresentationErrorCode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CategoryPatchProcessorTest extends TestCase
{
    private CommandBusInterface&MockObject $commandBus;

    private Operation&MockObject $operation;

    private CategoryPatchProcessor $processor;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())
            ->method('getName');
        $presenter = new CategoryResourcePresenter();

        $this->processor = new CategoryPatchProcessor(
            $this->commandBus,
            $presenter,
        );
    }

    public function testProcessWithValidInput(): void
    {
        $input = new CategoryPatchInput();
        $input->title = 'Updated category';
        $input->description = 'Updated description';
        $input->parent = $this->createCategoryResource('550e8400-e29b-41d4-a716-446655440001');

        $output = $this->createCategoryTree();
        $categoryId = '550e8400-e29b-41d4-a716-446655440000';

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($input, $categoryId, $output): CategoryItem {
                $this->assertInstanceOf(UpdateCategoryByAdminCommand::class, $command);
                $this->assertSame($input->title, $command->title);
                $this->assertSame($input->description, $command->description);
                $this->assertSame($categoryId, $command->categoryId);
                $this->assertSame($input->parent->id, $command->parentId);
                $this->assertTrue($command->parentProvided);

                return $output;
            });

        $result = $this->processor->process($input, $this->operation, ['id' => $categoryId]);

        $this->assertInstanceOf(CategoryResource::class, $result);
        $this->assertSame('Updated category', $result->title);
    }

    public function testProcessPreservesAnExplicitParentRemoval(): void
    {
        $input = new CategoryPatchInput();
        $input->parent = null;

        $output = $this->createCategoryTree();
        $categoryId = '550e8400-e29b-41d4-a716-446655440000';

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($output): CategoryItem {
                $this->assertInstanceOf(UpdateCategoryByAdminCommand::class, $command);
                $this->assertNull($command->parentId);
                $this->assertTrue($command->parentProvided);

                return $output;
            });

        $this->processor->process($input, $this->operation, ['id' => $categoryId]);
    }

    public function testProcessDoesNotMoveTheCategoryWhenParentIsOmitted(): void
    {
        $input = new CategoryPatchInput();
        $input->title = 'Updated category';

        $output = $this->createCategoryTree();
        $categoryId = '550e8400-e29b-41d4-a716-446655440000';

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($output): CategoryItem {
                $this->assertInstanceOf(UpdateCategoryByAdminCommand::class, $command);
                $this->assertNull($command->parentId);
                $this->assertFalse($command->parentProvided);

                return $output;
            });

        $this->processor->process($input, $this->operation, ['id' => $categoryId]);
    }

    public function testProcessThrowsLogicExceptionForInvalidInput(): void
    {
        $invalidInput = new stdClass();

        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process($invalidInput, $this->operation, ['id' => 'id']);
    }

    public function testProcessThrowsLogicExceptionWhenIdIsMissing(): void
    {
        $input = new CategoryPatchInput();

        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process($input, $this->operation, []);
    }

    public function testProcessThrowsLogicExceptionWhenIdIsNotString(): void
    {
        $input = new CategoryPatchInput();

        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process($input, $this->operation, ['id' => 123]);
    }

    private function createCategoryTree(): CategoryItem
    {
        $categoryId = CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $parentId = CategoryId::fromString('550e8400-e29b-41d4-a716-446655440001');
        $now = new DateTimeImmutable('2024-01-01 10:00:00');

        $category = Category::create(
            id: $categoryId,
            title: CategoryTitle::fromString('Updated category'),
            slug: Slug::fromString('updated-category'),
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
