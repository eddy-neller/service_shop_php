<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Customer;

use App\Domain\Customer\ValueObject\CustomerStatus;
use App\Infrastructure\Persistence\Mongo\Customer\CustomerDocument as Customer;
use App\Tests\Presentation\Api\BaseTest;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

final class CustomerTest extends BaseTest
{
    use CustomerTestDataTrait;

    protected const string URL_API_OPE = self::URL_API . 'shop/customers';

    public const array CRITERIA_IRI = ['status' => CustomerStatus::ACTIVE];

    private const string UNKNOWN_ID = '550e8400-e29b-41d4-a716-446655440099';

    private const string INVALID_ID = 'invalid-uuid';

    protected ?string $iri;

    protected function seedTestData(): void
    {
        $this->seedCustomerTestData();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Aucun compte n'existe ici : le `sub` du jeton est la seule identite d'utilisateur,
        // et c'est elle que le seeder Customer a posee comme `userAccountId`.
        $customer = $this->getInstance(Customer::class, [
            'userAccountId' => $this->userIdOf($this->userMember),
        ]);
        self::assertInstanceOf(Customer::class, $customer);

        $this->iri = self::URL_API_OPE . '/' . $customer->id;
    }

    public static function provideColShopCustomer(): Generator
    {
        $assertions = [
            BaseTest::ASSERTION_TYPE['SERIALIZATION'] => [
                'hasKey' => ['id', 'userAccountId', 'status', 'createdAt'],
                'hasNotKey' => ['nbAddress', 'addresses', 'updatedAt'],
            ],
        ];

        yield 'Admin: Normal' => [
            ['auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN']],
            $assertions,
        ];

        yield 'Admin: Pagin' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'],
                'query' => self::generateQuery(['page' => self::PAGIN_PAGE_ONE, 'ipp' => self::PAGIN_IPP]),
            ],
            $assertions,
        ];

        yield 'Admin: Filter' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'],
                'query' => self::generateQuery([
                    'filters' => [
                        ['filter' => 'search', 'field' => 'status', 'value' => CustomerStatus::ACTIVE],
                        ['filter' => 'order', 'field' => 'createdAt', 'sort' => 'DESC'],
                    ],
                ]),
            ],
            $assertions,
        ];
    }

    #[DataProvider('provideColShopCustomer')]
    public function testColShopCustomer(array $options, array $asserts): void
    {
        $this->testSuccess(Request::METHOD_GET, self::URL_API_OPE, $options, Response::HTTP_OK, $asserts);
    }

    public static function provideColShopCustomerException(): Generator
    {
        yield 'No role' => [
            [],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_UNAUTHORIZED, 'message' => 'HTTP 401 returned'],
        ];

        yield 'Not admin' => [
            ['auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER']],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_FORBIDDEN, 'message' => 'Access Denied'],
        ];
    }

    #[DataProvider('provideColShopCustomerException')]
    public function testColShopCustomerException(array $options, array $exception): void
    {
        $this->testException(Request::METHOD_GET, self::URL_API_OPE, $options, $exception);
    }

    public function testCreateShopCustomerSuccess(): void
    {
        // `userAccountId` n'est plus valide contre quoi que ce soit : ce service ne connait
        // aucun compte. N'importe quel UUID encore inutilise fait donc un client valide.
        $userAccountId = self::UNKNOWN_ID;

        $this->testSuccess(
            Request::METHOD_POST,
            self::URL_API_OPE,
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'],
                'json' => ['userAccountId' => $userAccountId],
            ],
            Response::HTTP_CREATED,
            [
                BaseTest::ASSERTION_TYPE['SERIALIZATION'] => [
                    'hasKey' => ['id', 'userAccountId', 'status', 'nbAddress', 'addresses', 'createdAt', 'updatedAt'],
                ],
                BaseTest::ASSERTION_TYPE['EQUAL'] => [
                    'userAccountId' => $userAccountId,
                    'status' => CustomerStatus::ACTIVE,
                ],
            ],
        );
    }

    public static function provideCreateCustomerException(): Generator
    {
        yield 'Empty' => [
            ['auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'], 'json' => []],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'userAccountId: This value should not be blank.'],
        ];

        yield 'Invalid UUID' => [
            ['auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'], 'json' => ['userAccountId' => self::INVALID_ID]],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'userAccountId: This value is not a valid UUID.'],
        ];

        yield 'No role' => [
            ['json' => ['userAccountId' => self::UNKNOWN_ID]],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_UNAUTHORIZED, 'message' => 'HTTP 401 returned'],
        ];

        yield 'Not admin' => [
            ['auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'], 'json' => ['userAccountId' => self::UNKNOWN_ID]],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_FORBIDDEN, 'message' => 'Access Denied'],
        ];
    }

    #[DataProvider('provideCreateCustomerException')]
    public function testCreateCustomerException(array $options, array $exception): void
    {
        $this->testException(Request::METHOD_POST, self::URL_API_OPE, $options, $exception);
    }

    public function testGetShopCustomer(): void
    {
        $this->testSuccess(
            Request::METHOD_GET,
            $this->iri,
            ['auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN']],
            Response::HTTP_OK,
            [
                BaseTest::ASSERTION_TYPE['SERIALIZATION'] => [
                    'hasKey' => ['id', 'userAccountId', 'status', 'nbAddress', 'addresses', 'createdAt', 'updatedAt'],
                ],
            ],
        );
    }

    public function testUpdateShopCustomerSuccess(): void
    {
        $this->testSuccess(
            Request::METHOD_PATCH,
            $this->iri,
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['status' => CustomerStatus::DISABLED],
            ],
            Response::HTTP_OK,
            [
                BaseTest::ASSERTION_TYPE['SERIALIZATION'] => [
                    'hasKey' => ['id', 'userAccountId', 'status', 'nbAddress', 'addresses', 'createdAt', 'updatedAt'],
                ],
                BaseTest::ASSERTION_TYPE['EQUAL'] => ['status' => CustomerStatus::DISABLED],
            ],
        );
    }

    public static function provideUpdateCustomerException(): Generator
    {
        yield 'No role' => [
            [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['status' => CustomerStatus::DISABLED],
            ],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_UNAUTHORIZED, 'message' => 'HTTP 401 returned'],
        ];

        yield 'Not admin' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['status' => CustomerStatus::DISABLED],
            ],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_FORBIDDEN, 'message' => 'Access Denied'],
        ];

        yield 'Invalid status' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['status' => CustomerStatus::ACTIVE],
            ],
            ['class' => ClientExceptionInterface::class, 'code' => Response::HTTP_UNPROCESSABLE_ENTITY, 'message' => 'status: Only disabling a customer is allowed.'],
        ];
    }

    #[DataProvider('provideUpdateCustomerException')]
    public function testUpdateCustomerException(array $options, array $exception): void
    {
        $this->testException(Request::METHOD_PATCH, $this->iri, $options, $exception);
    }

    /**
     * Les operations `/me` resolvent le client par `DisplayMyCustomerQuery`, qui refuse un client
     * desactive : lecture comme ecriture, panier comme adresses. Operations sans corps, pour que
     * la validation d'un payload ne reponde pas 422 avant la regle.
     *
     * Ce scenario prouve la regle de bout en bout, **pas** l'invalidation du cache : en test, le
     * cache de queries est un tableau par processus et le noyau reboote entre deux requetes, donc
     * rien n'y survit. La purge de `customer-of-user-{id}` a la desactivation est garantie par
     * `DomainEventCacheTagsTest`.
     */
    public static function provideDisabledCustomerMeOperation(): Generator
    {
        yield 'Get cart' => [Request::METHOD_GET, self::URL_API . 'shop/me/cart', false];
        yield 'Clear cart' => [Request::METHOD_DELETE, self::URL_API . 'shop/me/cart', false];
        yield 'List addresses' => [Request::METHOD_GET, self::URL_API . 'shop/me/addresses', false];
        yield 'Delete address' => [Request::METHOD_DELETE, self::URL_API . 'shop/me/addresses/', true];
    }

    #[DataProvider('provideDisabledCustomerMeOperation')]
    public function testDisabledCustomerLosesAccessToMeOperations(string $method, string $uri, bool $withAddressId): void
    {
        if ($withAddressId) {
            $customer = $this->getInstance(Customer::class, [
                'userAccountId' => $this->userIdOf($this->userMember),
            ]);
            self::assertInstanceOf(Customer::class, $customer);

            $address = $customer->addresses->first();
            self::assertNotFalse($address);

            $uri .= $address->id;
        }

        $this->testSuccess(
            Request::METHOD_PATCH,
            $this->iri,
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['status' => CustomerStatus::DISABLED],
            ],
            Response::HTTP_OK,
            [BaseTest::ASSERTION_TYPE['EQUAL'] => ['status' => CustomerStatus::DISABLED]],
        );

        $this->testException($method, $uri, ['auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER']], [
            'class' => ClientExceptionInterface::class,
            'code' => Response::HTTP_FORBIDDEN,
            // Le message metier, pas un « Access Denied » : le refus vient bien de la regle.
            'message' => 'Customer is disabled.',
        ]);
    }

    public static function provideCustomerNotFoundException(): Generator
    {
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];

        yield 'Get not found' => [Request::METHOD_GET, self::URL_API_OPE . '/' . self::UNKNOWN_ID, ['auth_bearer' => $adminToken]];
        yield 'Get invalid id' => [Request::METHOD_GET, self::URL_API_OPE . '/' . self::INVALID_ID, ['auth_bearer' => $adminToken]];
        yield 'Patch not found' => [
            Request::METHOD_PATCH,
            self::URL_API_OPE . '/' . self::UNKNOWN_ID,
            [
                'auth_bearer' => $adminToken,
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['status' => CustomerStatus::DISABLED],
            ],
        ];
        yield 'Patch invalid id' => [
            Request::METHOD_PATCH,
            self::URL_API_OPE . '/' . self::INVALID_ID,
            [
                'auth_bearer' => $adminToken,
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['status' => CustomerStatus::DISABLED],
            ],
        ];
    }

    #[DataProvider('provideCustomerNotFoundException')]
    public function testCustomerNotFoundException(string $method, string $uri, array $options): void
    {
        $this->testException($method, $uri, $options, [
            'class' => ClientExceptionInterface::class,
            'code' => Response::HTTP_NOT_FOUND,
        ]);
    }
}
