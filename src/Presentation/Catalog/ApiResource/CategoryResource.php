<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Presentation\Catalog\Dto\Category\CategoryPatchInput;
use App\Presentation\Catalog\Dto\Category\CategoryPostInput;
use App\Presentation\Catalog\State\Category\CategoryCollectionProvider;
use App\Presentation\Catalog\State\Category\CategoryDeleteProcessor;
use App\Presentation\Catalog\State\Category\CategoryGetProvider;
use App\Presentation\Catalog\State\Category\CategoryPatchProcessor;
use App\Presentation\Catalog\State\Category\CategoryPostProcessor;
use App\Presentation\RouteRequirements;
use App\Security\RoleSet;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

#[ApiResource(
    shortName: 'ShopCategory',
    operations: [
        new Get(
            uriTemplate: '/categories/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            cacheHeaders: [
                'max_age' => 21600,
                'shared_max_age' => 86400,
            ],
            name: self::PREFIX_NAME . 'get',
            provider: CategoryGetProvider::class,
        ),
        new Patch(
            uriTemplate: '/categories/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('" . RoleSet::ROLE_ADMIN . "')",
            input: CategoryPatchInput::class,
            read: false,
            name: self::PREFIX_NAME . 'patch',
            processor: CategoryPatchProcessor::class,
        ),
        new Delete(
            uriTemplate: '/categories/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            status: 204,
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('" . RoleSet::ROLE_ADMIN . "')",
            output: false,
            read: false,
            name: self::PREFIX_NAME . 'delete',
            processor: CategoryDeleteProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/categories',
            cacheHeaders: [
                'max_age' => 21600,
                'shared_max_age' => 86400,
            ],
            openapi: new Model\Operation(
                parameters: [
                    new Model\Parameter(
                        name: 'level',
                        in: 'query',
                        required: false,
                        schema: [
                            'type' => 'integer',
                        ],
                    ),
                    new Model\Parameter(
                        name: 'parent',
                        in: 'query',
                        required: false,
                        schema: [
                            'type' => 'string',
                            'format' => 'uuid',
                        ],
                    ),
                    new Model\Parameter(
                        name: 'order',
                        in: 'query',
                        required: false,
                        schema: [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                                'level' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                                'nbProduct' => ['type' => 'string', 'enum' => ['asc', 'desc']],
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
            provider: CategoryCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/categories',
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('" . RoleSet::ROLE_ADMIN . "')",
            input: CategoryPostInput::class,
            name: self::PREFIX_NAME . 'post',
            processor: CategoryPostProcessor::class,
        ),
    ],
    routePrefix: '/shop',
    normalizationContext: [AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true],
)]
final class CategoryResource
{
    private const string PREFIX_NAME = 'shop-categories-';

    #[Groups(['shop_category:read', 'shop_product:read'])]
    public string $id;

    #[Groups(['shop_category:read', 'shop_category:write', 'shop_product:read'])]
    public string $title;

    #[Groups(['shop_category:item:read', 'shop_category:write'])]
    public ?string $description = null;

    #[Groups(['shop_category:read', 'shop_product:item:read'])]
    public int $nbProduct = 0;

    #[Groups(['shop_category:read'])]
    public string $slug;

    #[Groups(['shop_category:item:read', 'shop_category:write'])]
    #[MaxDepth(1)]
    public ?self $parent = null;

    #[Groups(['shop_category:item:read'])]
    #[MaxDepth(1)]
    public ?array $children = null;

    #[Groups(['shop_category:read', 'shop_product:item:read'])]
    public int $level = 0;

    #[Groups(['shop_category:read'])]
    public bool $hasChildren = false;

    #[Groups(['shop_category:read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['shop_category:item:read'])]
    public DateTimeImmutable $updatedAt;
}
