<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Command;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\UseCase\Command\DeleteCategoryByAdmin\DeleteCategoryByAdminCommand;
use App\Application\Catalog\UseCase\Command\DeleteCategoryByAdmin\DeleteCategoryByAdminCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeleteCategoryByAdminTest extends TestCase
{
    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440000';

    private CategoryRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    private DeleteCategoryByAdminCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CategoryRepositoryInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);
        $this->handler = new DeleteCategoryByAdminCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
        );
    }

    public function testHandleDeletesCategoryAndUpdatesTimestamp(): void
    {
        $now = new DateTimeImmutable('2024-03-01 10:00:00');
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $category = $this->createCategory($categoryId);

        $command = new DeleteCategoryByAdminCommand($categoryId->toString());

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->repository->expects($this->once())
            ->method('delete')
            ->with($category);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                $callback();
            });

        $this->handler->handle($command);

        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testHandleThrowsWhenCategoryNotFound(): void
    {
        $this->clock->expects($this->never())
            ->method('now');
        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());

        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $command = new DeleteCategoryByAdminCommand($categoryId->toString());

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn(null);

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Category not found.');

        $this->handler->handle($command);
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
