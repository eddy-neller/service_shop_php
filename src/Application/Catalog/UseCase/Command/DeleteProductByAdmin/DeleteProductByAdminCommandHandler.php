<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\DeleteProductByAdmin;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductImageStorageInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\ValueObject\ProductId;

final readonly class DeleteProductByAdminCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private CategoryRepositoryInterface $categoryRepository,
        private ProductImageStorageInterface $imageStorage,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(DeleteProductByAdminCommand $command): void
    {
        $productId = ProductId::fromString($command->productId);

        $imageName = $this->transactional->transactional(function () use ($productId): ?string {
            $product = $this->productRepository->findById($productId);

            if (null === $product) {
                throw new ProductNotFoundException();
            }

            $category = $this->categoryRepository->findById($product->getCategoryId());

            if (null === $category) {
                throw new CategoryNotFoundException();
            }

            $now = $this->clock->now();

            $product->delete($now);
            $category->decreaseProductCount($now);

            $this->categoryRepository->save($category);

            $this->productRepository->delete($product);
            $this->eventBus->publishAll($product->releaseEvents());

            return $product->getImageName();
        });

        if (null !== $imageName) {
            $this->imageStorage->remove($imageName);
        }
    }
}
