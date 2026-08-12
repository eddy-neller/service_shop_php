<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\State\Product;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Catalog\UseCase\Command\CreateProductByAdmin\CreateProductByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Presentation\Catalog\ApiResource\ProductResource;
use App\Presentation\Catalog\Dto\Product\ProductPostInput;
use App\Presentation\Catalog\Presenter\ProductResourcePresenter;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;

final readonly class ProductPostProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private ProductResourcePresenter $productResourcePresenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        if (!$data instanceof ProductPostInput) {
            throw new LogicException(PresentationErrorCode::INVALID_INPUT->value);
        }

        $command = new CreateProductByAdminCommand(
            title: $data->title,
            subtitle: $data->subtitle,
            description: $data->description,
            price: $data->price,
            categoryId: $data->category->id,
        );

        $output = $this->commandBus->dispatch($command);

        return $this->productResourcePresenter->toResource($output);
    }
}
