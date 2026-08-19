<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\Model\RequestBody;
use App\Infrastructure\Symfony\Security\RoleSet;
use App\Presentation\Catalog\Dto\Product\ProductImageInput;
use App\Presentation\Catalog\Dto\Product\ProductPatchInput;
use App\Presentation\Catalog\Dto\Product\ProductPostInput;
use App\Presentation\Catalog\State\Product\ProductCollectionProvider;
use App\Presentation\Catalog\State\Product\ProductDeleteProcessor;
use App\Presentation\Catalog\State\Product\ProductGetProvider;
use App\Presentation\Catalog\State\Product\ProductImageProcessor;
use App\Presentation\Catalog\State\Product\ProductPatchProcessor;
use App\Presentation\Catalog\State\Product\ProductPostProcessor;
use App\Presentation\RouteRequirements;
use ArrayObject;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'ShopProduct',
    operations: [
        new Get(
            uriTemplate: '/products/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            cacheHeaders: [
                'max_age' => 21600,
                'shared_max_age' => 86400,
            ],
            name: self::PREFIX_NAME . 'get',
            provider: ProductGetProvider::class,
        ),
        new Patch(
            uriTemplate: '/products/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('" . RoleSet::ROLE_ADMIN . "')",
            input: ProductPatchInput::class,
            read: false,
            name: self::PREFIX_NAME . 'patch',
            processor: ProductPatchProcessor::class,
        ),
        new Delete(
            uriTemplate: '/products/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            status: 204,
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('" . RoleSet::ROLE_ADMIN . "')",
            output: false,
            read: false,
            name: self::PREFIX_NAME . 'delete',
            processor: ProductDeleteProcessor::class,
        ),
        new Post(
            uriTemplate: '/products',
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('" . RoleSet::ROLE_ADMIN . "')",
            input: ProductPostInput::class,
            name: self::PREFIX_NAME . 'post',
            processor: ProductPostProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/products',
            cacheHeaders: [
                'max_age' => 21600,
                'shared_max_age' => 86400,
            ],
            openapi: new Model\Operation(
                parameters: [
                    new Model\Parameter(
                        name: 'title',
                        in: 'query',
                        required: false,
                        schema: ['type' => 'string'],
                    ),
                    new Model\Parameter(
                        name: 'subtitle',
                        in: 'query',
                        required: false,
                        schema: ['type' => 'string'],
                    ),
                    new Model\Parameter(
                        name: 'description',
                        in: 'query',
                        required: false,
                        schema: ['type' => 'string'],
                    ),
                    new Model\Parameter(
                        name: 'category',
                        in: 'query',
                        required: false,
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                    new Model\Parameter(
                        name: 'order',
                        in: 'query',
                        required: false,
                        schema: [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                                'category.title' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                                'price' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                                'createdAt' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                            ],
                        ],
                        style: 'deepObject',
                        explode: true,
                    ),
                ]
            ),
            paginationClientItemsPerPage: true,
            name: self::PREFIX_NAME . 'col',
            provider: ProductCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/products/{id}/image',
            inputFormats: ['multipart' => ['multipart/form-data']],
            requirements: ['id' => RouteRequirements::UUID],
            openapi: new Model\Operation(
                summary: 'Creates the Image of a Shop Product resource',
                requestBody: new RequestBody(
                    content: new ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'imageFile' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                        'description' => 'Shop product image',
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
                security: [['JWT' => []]]
            ),
            security: "is_granted('" . RoleSet::ROLE_ADMIN . "')",
            input: ProductImageInput::class,
            name: self::PREFIX_NAME . 'image',
            processor: ProductImageProcessor::class,
        ),
    ],
    routePrefix: '/shop',
    order: ['createdAt' => 'DESC'],
)]
final class ProductResource
{
    private const string PREFIX_NAME = 'shop-products-';

    #[Groups(['shop_product:read'])]
    public string $id;

    #[Groups(['shop_product:read', 'shop_product:write'])]
    public string $title;

    #[Groups(['shop_product:item:read', 'shop_product:write'])]
    public string $subtitle;

    #[Groups(['shop_product:item:read', 'shop_product:write'])]
    public string $description;

    #[Groups(['shop_product:read', 'shop_product:write'])]
    public float $price;

    #[Groups(['shop_product:read'])]
    public string $slug;

    #[Groups(['shop_product:read'])]
    public ?string $imageUrl = null;

    #[ApiProperty(security: "is_granted('ROLE_ADMIN')")]
    #[Groups(['shop_product:read', 'shop_product:write'])]
    public CategoryResource $category;

    #[Groups(['shop_product:read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['shop_product:item:read'])]
    public DateTimeImmutable $updatedAt;
}
