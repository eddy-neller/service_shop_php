<?php

declare(strict_types=1);

namespace App\Presentation\Customer\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Presentation\Customer\Dto\AddressPatchInput;
use App\Presentation\Customer\Dto\AddressPostInput;
use App\Presentation\Customer\State\Address\AddressCollectionProvider;
use App\Presentation\Customer\State\Address\AddressDefaultProcessor;
use App\Presentation\Customer\State\Address\AddressDeleteProcessor;
use App\Presentation\Customer\State\Address\AddressGetProvider;
use App\Presentation\Customer\State\Address\AddressPatchProcessor;
use App\Presentation\Customer\State\Address\AddressPostProcessor;
use App\Presentation\RouteRequirements;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * L'appartenance n'est plus portee par un voter.
 *
 * Le monolithe gardait chaque operation d'item par `is_granted('shop_address:item:*', object)`,
 * adosse a `ShopAddressVoter` qui remontait de l'adresse au client par Doctrine. Les adresses
 * etant desormais **dans** le document du client, la question ne se pose plus : chaque State
 * resout le client courant puis lui demande son adresse. Celle d'autrui est introuvable, donc
 * **404** et non 403 — ce qui supprime au passage l'oracle d'existence qu'offrait le 403.
 */
#[ApiResource(
    shortName: 'ShopAddress',
    operations: [
        new Get(
            uriTemplate: '/addresses/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: self::PREFIX_NAME . 'me-get',
            provider: AddressGetProvider::class,
        ),
        new Patch(
            uriTemplate: '/addresses/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            input: AddressPatchInput::class,
            read: false,
            name: self::PREFIX_NAME . 'me-patch',
            processor: AddressPatchProcessor::class,
        ),
        new Delete(
            uriTemplate: '/addresses/{id}',
            requirements: ['id' => RouteRequirements::UUID],
            status: 204,
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            output: false,
            read: false,
            name: self::PREFIX_NAME . 'me-delete',
            processor: AddressDeleteProcessor::class,
        ),
        new Post(
            uriTemplate: '/addresses/{id}/default',
            requirements: ['id' => RouteRequirements::UUID],
            status: 200,
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            input: false,
            read: false,
            name: self::PREFIX_NAME . 'me-default',
            processor: AddressDefaultProcessor::class,
        ),
        new Post(
            uriTemplate: '/addresses',
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            input: AddressPostInput::class,
            read: false,
            name: self::PREFIX_NAME . 'me-post',
            processor: AddressPostProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/addresses',
            openapi: new Model\Operation(
                security: [['JWT' => []]]
            ),
            paginationClientItemsPerPage: true,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: self::PREFIX_NAME . 'me-col',
            provider: AddressCollectionProvider::class,
        ),
    ],
    routePrefix: '/shop/me',
    order: ['createdAt' => 'DESC'],
)]
final class AddressResource
{
    private const string PREFIX_NAME = 'shop-addresses-';

    #[Groups(['shop_address:read', 'shop_customer:item:read'])]
    public string $id;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public string $name;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public string $firstname;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public string $lastname;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public ?string $company = null;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public string $address;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public string $zip;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public string $city;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public string $country;

    #[Groups(['shop_address:read', 'shop_address:write', 'shop_customer:item:read'])]
    public string $phone;

    #[Groups(['shop_address:read', 'shop_customer:item:read'])]
    public bool $isDefault = false;

    #[Groups(['shop_address:read', 'shop_customer:item:read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['shop_address:read', 'shop_customer:item:read'])]
    public DateTimeImmutable $updatedAt;
}
