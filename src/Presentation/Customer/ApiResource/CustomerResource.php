<?php

declare(strict_types=1);

namespace App\Presentation\Customer\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Domain\Customer\ValueObject\CustomerStatus;
use App\Infrastructure\Symfony\Security\RoleSet;
use App\Presentation\Customer\Dto\CustomerPatchInput;
use App\Presentation\Customer\Dto\CustomerPostInput;
use App\Presentation\Customer\State\Customer\CustomerCollectionProvider;
use App\Presentation\Customer\State\Customer\CustomerGetProvider;
use App\Presentation\Customer\State\Customer\CustomerPatchProcessor;
use App\Presentation\Customer\State\Customer\CustomerPostProcessor;
use App\Presentation\RouteRequirements;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'ShopCustomer',
    operations: [
        new Get(
            uriTemplate: '/customers/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            name: self::PREFIX_NAME . 'get',
            provider: CustomerGetProvider::class,
        ),
        new Patch(
            uriTemplate: '/customers/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            input: CustomerPatchInput::class,
            read: false,
            name: self::PREFIX_NAME . 'patch',
            processor: CustomerPatchProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/customers',
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            paginationClientItemsPerPage: true,
            name: self::PREFIX_NAME . 'col',
            provider: CustomerCollectionProvider::class,
        ),
        new Post(
            uriTemplate: '/customers',
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            input: CustomerPostInput::class,
            read: false,
            name: self::PREFIX_NAME . 'post',
            processor: CustomerPostProcessor::class,
        ),
    ],
    routePrefix: '/shop',
    order: ['createdAt' => 'DESC'],
    security: "is_granted('" . RoleSet::ROLE_ADMIN . "')",
)]
final class CustomerResource
{
    private const string PREFIX_NAME = 'shop-customers-';

    #[Groups(['shop_customer:read'])]
    public string $id;

    #[Groups(['shop_customer:read', 'shop_customer:write'])]
    public string $userAccountId;

    #[Groups(['shop_customer:read'])]
    public int $status = CustomerStatus::ACTIVE;

    #[Groups(['shop_customer:item:read'])]
    public int $nbAddress = 0;

    /** @var AddressResource[] */
    #[Groups(['shop_customer:item:read'])]
    public array $addresses = [];

    #[Groups(['shop_customer:read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['shop_customer:item:read'])]
    public DateTimeImmutable $updatedAt;
}
