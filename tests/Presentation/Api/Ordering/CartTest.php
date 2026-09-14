<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Ordering;

use App\Infrastructure\Persistence\Mongo\Catalog\ProductDocument as Product;
use App\Tests\Presentation\Api\BaseTest;
use App\Tests\Presentation\Api\Catalog\CatalogTestDataTrait;
use App\Tests\Presentation\Api\Catalog\ProductTestDataSeeder;
use App\Tests\Presentation\Api\Customer\CustomerTestDataTrait;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

final class CartTest extends BaseTest
{
    use CatalogTestDataTrait;
    use CustomerTestDataTrait;

    protected const string URL_API_OPE = self::URL_API . 'shop/me/cart';

    private const string UNKNOWN_PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440099';

    private const string INVALID_ID = 'invalid-uuid';

    protected string $productId;

    protected function seedTestData(): void
    {
        $this->seedCatalogTestData();
        $this->seedCustomerTestData();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->productId = $this->getAnyProductId();
    }

    public static function provideGetShopCartSuccess(): Generator
    {
        // Aucun panier n'est seme : le premier `GET` porte donc sur un client qui n'en a pas
        // encore. `CartItemFactory` rend alors un panier vide dont `id`, `createdAt` et
        // `updatedAt` sont nuls — et le serializer omet les valeurs nulles. Le panier n'est
        // cree qu'au premier ajout.
        yield 'Member: No cart yet' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
            ],
            [
                BaseTest::ASSERTION_TYPE['SERIALIZATION'] => [
                    'hasKey' => ['items', 'totalQuantity', 'subtotal', 'currency'],
                    'hasNotKey' => ['id', 'createdAt', 'updatedAt'],
                ],
                BaseTest::ASSERTION_TYPE['EQUAL'] => [
                    'items' => [],
                    'totalQuantity' => 0,
                    'subtotal' => 0,
                    'currency' => 'EUR',
                ],
            ],
        ];
    }

    #[DataProvider('provideGetShopCartSuccess')]
    public function testGetShopCartSuccess(array $options, array $asserts): void
    {
        $this->clearCart();

        $this->testSuccess(
            Request::METHOD_GET,
            self::URL_API_OPE,
            $options,
            Response::HTTP_OK,
            $asserts,
        );
    }

    public static function provideGetShopCartWithItemsSuccess(): Generator
    {
        yield 'Member: Cart with item' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
            ],
            2,
        ];
    }

    #[DataProvider('provideGetShopCartWithItemsSuccess')]
    public function testGetShopCartWithItemsSuccess(array $options, int $quantity): void
    {
        $this->clearCart();
        $this->addCartItem($this->productId, $quantity);

        $cart = $this->testSuccess(
            Request::METHOD_GET,
            self::URL_API_OPE,
            $options,
            Response::HTTP_OK,
        );

        $this->assertIsArray($cart);
        $this->assertCartContainsProduct($cart, $this->productId, $quantity);
    }

    public static function provideAddShopCartItemSuccess(): Generator
    {
        yield 'Member: Add item' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
            ],
            2,
        ];
    }

    #[DataProvider('provideAddShopCartItemSuccess')]
    public function testAddShopCartItemSuccess(array $options, int $quantity): void
    {
        $this->clearCart();
        $options['json'] = ['productId' => $this->productId, 'quantity' => $quantity];

        $created = $this->testSuccess(
            Request::METHOD_POST,
            self::URL_API_OPE . '/items',
            $options,
            Response::HTTP_CREATED,
        );
        $this->assertIsArray($created);
        $this->assertCartContainsProduct($created, $this->productId, $quantity);
    }

    public static function provideUpdateShopCartItemSuccess(): Generator
    {
        yield 'Member: Update item' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ],
            1,
            4,
        ];
    }

    #[DataProvider('provideUpdateShopCartItemSuccess')]
    public function testUpdateShopCartItemSuccess(array $options, int $initialQuantity, int $updatedQuantity): void
    {
        $this->clearCart();
        $this->addCartItem($this->productId, $initialQuantity);
        $options['json'] = ['quantity' => $updatedQuantity];

        $cart = $this->testSuccess(
            Request::METHOD_PATCH,
            self::URL_API_OPE . '/items/' . $this->productId,
            $options,
            Response::HTTP_OK,
        );

        $this->assertIsArray($cart);
        $this->assertCartContainsProduct($cart, $this->productId, $updatedQuantity);
    }

    public static function provideDeleteShopCartItemSuccess(): Generator
    {
        yield 'Member: Delete item' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
            ],
            1,
        ];
    }

    #[DataProvider('provideDeleteShopCartItemSuccess')]
    public function testDeleteShopCartItemSuccess(array $options, int $quantity): void
    {
        $this->clearCart();
        $this->addCartItem($this->productId, $quantity);

        $this->testSuccess(
            Request::METHOD_DELETE,
            self::URL_API_OPE . '/items/' . $this->productId,
            $options,
            Response::HTTP_NO_CONTENT,
        );
    }

    public static function provideClearShopCartSuccess(): Generator
    {
        yield 'Member: Clear cart' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
            ],
            1,
        ];
    }

    #[DataProvider('provideClearShopCartSuccess')]
    public function testClearShopCartSuccess(array $options, int $quantity): void
    {
        $this->clearCart();
        $this->addCartItem($this->productId, $quantity);

        $this->testSuccess(
            Request::METHOD_DELETE,
            self::URL_API_OPE,
            $options,
            Response::HTTP_NO_CONTENT,
        );
    }

    public static function provideGetShopCartException(): Generator
    {
        yield 'No role' => [
            [],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];
    }

    #[DataProvider('provideGetShopCartException')]
    public function testGetShopCartException(array $options, array $exception): void
    {
        $this->testException(Request::METHOD_GET, self::URL_API_OPE, $options, $exception);
    }

    public static function provideClearShopCartException(): Generator
    {
        yield 'No role' => [
            [],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];
    }

    #[DataProvider('provideClearShopCartException')]
    public function testClearShopCartException(array $options, array $exception): void
    {
        $this->testException(Request::METHOD_DELETE, self::URL_API_OPE, $options, $exception);
    }

    public static function provideAddShopCartItemException(): Generator
    {
        yield 'No role' => [
            ['json' => ['productId' => self::UNKNOWN_PRODUCT_ID, 'quantity' => 1]],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];

        yield 'Empty' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
                'json' => [],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'productId: This value should not be blank.',
            ],
        ];

        yield 'Invalid product id' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
                'json' => ['productId' => self::INVALID_ID, 'quantity' => 1],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'productId: This value is not a valid UUID.',
            ],
        ];

        yield 'Invalid quantity' => [
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
                'json' => ['productId' => self::UNKNOWN_PRODUCT_ID, 'quantity' => 100],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'quantity: This value should be between 1 and 99.',
            ],
        ];
    }

    #[DataProvider('provideAddShopCartItemException')]
    public function testAddShopCartItemException(array $options, array $exception): void
    {
        $this->testException(Request::METHOD_POST, self::URL_API_OPE . '/items', $options, $exception);
    }

    public static function provideUpdateShopCartItemException(): Generator
    {
        yield 'No role' => [
            self::UNKNOWN_PRODUCT_ID,
            [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['quantity' => 1],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];

        yield 'Invalid product id' => [
            self::INVALID_ID,
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['quantity' => 1],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_NOT_FOUND,
                'message' => null,
            ],
        ];

        yield 'Invalid quantity' => [
            self::UNKNOWN_PRODUCT_ID,
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['quantity' => 100],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'quantity: This value should be between 0 and 99.',
            ],
        ];
    }

    #[DataProvider('provideUpdateShopCartItemException')]
    public function testUpdateShopCartItemException(
        string $productId,
        array $options,
        array $exception,
    ): void {
        $this->testException(Request::METHOD_PATCH, self::URL_API_OPE . '/items/' . $productId, $options, $exception);
    }

    public static function provideDeleteShopCartItemException(): Generator
    {
        yield 'No role' => [
            self::UNKNOWN_PRODUCT_ID,
            [],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];
    }

    #[DataProvider('provideDeleteShopCartItemException')]
    public function testDeleteShopCartItemException(
        string $productId,
        array $options,
        array $exception,
    ): void {
        $this->testException(Request::METHOD_DELETE, self::URL_API_OPE . '/items/' . $productId, $options, $exception);
    }

    private function getAnyProductId(): string
    {
        $product = $this->getInstance(Product::class, ['title' => ProductTestDataSeeder::ANCHOR_PRODUCT_TITLE]);
        self::assertInstanceOf(Product::class, $product);

        return $product->id;
    }

    private function addCartItem(string $productId, int $quantity): void
    {
        $this->testSuccess(
            Request::METHOD_POST,
            self::URL_API_OPE . '/items',
            [
                'auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER'],
                'json' => ['productId' => $productId, 'quantity' => $quantity],
            ],
            Response::HTTP_CREATED,
        );
    }

    private function clearCart(): void
    {
        $this->testSuccess(
            Request::METHOD_DELETE,
            self::URL_API_OPE,
            ['auth_bearer' => self::PLACEHOLDERS['TOKENS']['MEMBER']],
            Response::HTTP_NO_CONTENT,
        );
    }

    private function assertCartContainsProduct(array $cart, string $productId, int $quantity): void
    {
        $this->assertArrayHasKey('id', $cart);
        $this->assertArrayHasKey('items', $cart);
        $this->assertArrayHasKey('totalQuantity', $cart);
        $this->assertArrayHasKey('subtotal', $cart);
        $this->assertArrayHasKey('currency', $cart);
        $this->assertArrayHasKey('createdAt', $cart);
        $this->assertArrayHasKey('updatedAt', $cart);
        $this->assertNotNull($cart['id']);
        $this->assertSame($quantity, $cart['totalQuantity']);
        $this->assertSame('EUR', $cart['currency']);
        $this->assertNotEmpty($cart['items']);

        $line = $cart['items'][0];
        $this->assertArrayHasKey('id', $line);
        $this->assertArrayHasKey('productId', $line);
        $this->assertArrayHasKey('productTitle', $line);
        $this->assertArrayHasKey('productSlug', $line);
        $this->assertArrayHasKey('unitPrice', $line);
        $this->assertArrayHasKey('quantity', $line);
        $this->assertArrayHasKey('lineTotal', $line);
        $this->assertSame($productId, $line['productId']);
        $this->assertSame($quantity, $line['quantity']);
    }
}
