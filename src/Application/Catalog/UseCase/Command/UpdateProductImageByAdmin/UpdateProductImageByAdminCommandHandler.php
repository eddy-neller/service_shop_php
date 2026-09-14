<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCase\Command\UpdateProductImageByAdmin;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductImageStorageInterface;
use App\Application\Catalog\Port\ProductImageValidatorInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\ValueObject\ProductId;
use Exception;

final readonly class UpdateProductImageByAdminCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private CategoryRepositoryInterface $categoryRepository,
        private ProductImageValidatorInterface $imageValidator,
        private ProductImageStorageInterface $imageStorage,
        private ClockInterface $clock,
        private TransactionalInterface $transactional,
        private DomainEventBusInterface $eventBus,
    ) {
    }

    public function handle(UpdateProductImageByAdminCommand $command): ProductItem
    {
        $productId = ProductId::fromString($command->productId);
        $this->imageValidator->validate($command->imageFile);

        $fileName = $this->imageStorage->store($command->imageFile);

        try {
            $update = $this->transactional->transactional(function () use ($productId, $fileName): array {
                $product = $this->productRepository->findById($productId);

                if (null === $product) {
                    throw new ProductNotFoundException();
                }

                $previousImageName = $product->getImageName();
                $product->updateImage($fileName, $this->clock->now());

                $this->productRepository->save($product);
                $this->eventBus->publishAll($product->releaseEvents());

                $category = $this->categoryRepository->findById($product->getCategoryId());
                if (null === $category) {
                    throw new CategoryNotFoundException();
                }

                return [
                    'previousImageName' => $previousImageName,
                    'product' => ProductItem::fromProduct($product, $category),
                ];
            });
        } catch (Exception $exception) {
            $this->imageStorage->remove($fileName);

            throw $exception;
        }

        if (null !== $update['previousImageName']) {
            $this->imageStorage->remove($update['previousImageName']);
        }

        return $update['product'];
    }
}
