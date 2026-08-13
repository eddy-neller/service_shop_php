<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Catalog;

use App\Infrastructure\Persistence\Mongo\Catalog\CategoryDocument as Category;
use App\Infrastructure\Persistence\Mongo\Catalog\ProductDocument as Product;
use App\Tests\Presentation\Api\BaseTest;
use Faker\Factory;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

final class ProductTest extends BaseTest
{
    protected const string URL_API_OPE = self::URL_API . 'shop/products';

    private const string URL_API_CATEGORY_OPE = self::URL_API . 'shop/categories';

    public const array CRITERIA_IRI = ['title' => 'Product title 1'];

    protected const array CATEGORY_CRITERIA_IRI = ['title' => 'Shop category level 1 title 1'];

    private const string UNKNOWN_ID = '550e8400-e29b-41d4-a716-446655440099';

    private const string INVALID_ID = 'invalid-uuid';

    protected ?string $iri;

    protected ?string $categoryIri;

    protected function setUp(): void
    {
        parent::setUp();

        $product = $this->getInstance(Product::class, self::CRITERIA_IRI);
        self::assertInstanceOf(Product::class, $product);

        $category = $this->getInstance(Category::class, self::CATEGORY_CRITERIA_IRI);
        self::assertInstanceOf(Category::class, $category);

        $this->iri = self::URL_API_OPE . '/' . $product->id;
        $this->categoryIri = self::URL_API_CATEGORY_OPE . '/' . $category->id;
    }

    public static function provideColCatalogProduct(): Generator
    {
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];

        $assertions = [
            BaseTest::ASSERTION_TYPE['SERIALIZATION'] => [
                'hasKey' => [
                    'id',
                    'title',
                    'price',
                    'slug',
                    'imageUrl',
                    ['category' => ['id', 'title']],
                    'createdAt',
                ],
                'hasNotKey' => [
                    'subtitle',
                    'description',
                    'imageFile',
                    'updatedAt',
                ],
            ],
        ];

        yield 'Normal' => [
            [
                'auth_bearer' => $adminToken,
            ],
            $assertions,
        ];

        yield 'Pagin' => [
            [
                'auth_bearer' => $adminToken,
                'query' => self::generateQuery(
                    [
                        'page' => self::PAGIN_PAGE_ONE,
                        'ipp' => self::PAGIN_IPP,
                    ]
                ),
            ],
            $assertions,
        ];

        yield 'Filter' => [
            [
                'auth_bearer' => $adminToken,
                'query' => self::generateQuery(
                    [
                        'filters' => [
                            [
                                'filter' => 'search',
                                'field' => 'title',
                                'value' => 'Product',
                            ],
                            [
                                'filter' => 'order',
                                'field' => 'price',
                                'sort' => 'ASC',
                            ],
                        ],
                    ]
                ),
            ],
            $assertions,
        ];
    }

    #[DataProvider('provideColCatalogProduct')]
    public function testColCatalogProduct(
        array $options,
        array $asserts,
    ): void {
        $this->testSuccess(
            Request::METHOD_GET,
            self::URL_API_OPE,
            $options,
            Response::HTTP_OK,
            $asserts,
        );
    }

    public function testColCatalogProductFiltersByCategory(): void
    {
        $response = $this->request(
            Request::METHOD_GET,
            self::URL_API_OPE,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getToken($this->userAdmin),
                ],
                'query' => [
                    'category' => $this->categoryIri,
                ],
            ],
        );

        self::assertNotNull($response);
        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $items = $response->toArray();
        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertSame($this->categoryIri, self::URL_API_CATEGORY_OPE . '/' . $item['category']['id']);
        }
    }

    public static function provideCreateCatalogProductSuccess(): Generator
    {
        $fakeData = self::getFakeDataCatalogProduct();
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];
        $categoryIri = 'CATEGORY_IRI_PLACEHOLDER';

        $assertSerialization = [
            'hasKey' => [
                'id',
                'title',
                'subtitle',
                'description',
                'price',
                'slug',
                ['category' => ['id', 'title']],
                'createdAt',
                'updatedAt',
            ],
            'hasNotKey' => [
                'imageFile',
            ],
        ];

        yield 'Full' => [
            [
                'auth_bearer' => $adminToken,
                'json' => [
                    'title' => $fakeData['title'],
                    'subtitle' => $fakeData['subtitle'],
                    'description' => $fakeData['description'],
                    'price' => $fakeData['price'],
                    'category' => $categoryIri,
                ],
            ],
            [
                BaseTest::ASSERTION_TYPE['SERIALIZATION'] => $assertSerialization,
                BaseTest::ASSERTION_TYPE['EQUAL'] => [
                    'title' => $fakeData['title'],
                    'subtitle' => $fakeData['subtitle'],
                    'description' => $fakeData['description'],
                    'price' => $fakeData['price'],
                ],
            ],
        ];
    }

    #[DataProvider('provideCreateCatalogProductSuccess')]
    public function testCreateCatalogProductSuccess(
        array $options,
        array $asserts,
    ): void {
        // Remplacer le placeholder de catégorie par l'IRI réel
        if (isset($options['json']['category']) && 'CATEGORY_IRI_PLACEHOLDER' === $options['json']['category']) {
            $options['json']['category'] = $this->categoryIri;
        }

        $this->testSuccess(
            Request::METHOD_POST,
            self::URL_API_OPE,
            $options,
            Response::HTTP_CREATED,
            $asserts,
        );
    }

    public static function provideCreateProductException(): Generator
    {
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];
        $memberToken = self::PLACEHOLDERS['TOKENS']['MEMBER'];
        $categoryIri = 'CATEGORY_IRI_PLACEHOLDER';

        yield 'Empty' => [
            [
                'auth_bearer' => $adminToken,
                'json' => [],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'title: This value should not be blank.',
            ],
        ];

        yield 'Negative price' => [
            [
                'auth_bearer' => $adminToken,
                'json' => [
                    'title' => 'Test Product',
                    'subtitle' => 'Test subtitle',
                    'description' => 'Test description',
                    'price' => -10.50,
                    'category' => $categoryIri,
                ],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'price: This value should be either positive or zero.',
            ],
        ];

        yield 'Same title' => [
            [
                'auth_bearer' => $adminToken,
                'json' => [
                    'title' => 'Product title 1',
                    'subtitle' => 'Test subtitle',
                    'description' => 'Test description',
                    'price' => 29.99,
                    'category' => $categoryIri,
                ],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_CONFLICT,
                'message' => 'Product title is already used.',
            ],
        ];

        yield 'No role' => [
            [
                'json' => [
                    'title' => 'Test Product',
                    'subtitle' => 'Test subtitle',
                    'description' => 'Test description',
                    'price' => 29.99,
                    'category' => $categoryIri,
                ],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];

        yield 'Not admin' => [
            [
                'auth_bearer' => $memberToken,
                'json' => [
                    'title' => 'Test Product',
                    'subtitle' => 'Test subtitle',
                    'description' => 'Test description',
                    'price' => 29.99,
                    'category' => $categoryIri,
                ],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_FORBIDDEN,
                'message' => 'Access Denied',
            ],
        ];
    }

    #[DataProvider('provideCreateProductException')]
    public function testCreateProductException(
        array $options,
        array $exception,
    ): void {
        // Remplacer le placeholder de catégorie par l'IRI réel
        if (isset($options['json']['category']) && 'CATEGORY_IRI_PLACEHOLDER' === $options['json']['category']) {
            $options['json']['category'] = $this->categoryIri;
        }

        $this->testException(Request::METHOD_POST, self::URL_API_OPE, $options, $exception);
    }

    public function testGetCatalogProduct(): void
    {
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];

        $assertSerialization = [
            'hasKey' => [
                'id',
                'title',
                'subtitle',
                'description',
                'price',
                'slug',
                'imageUrl',
                ['category' => ['id', 'title']],
                'createdAt',
                'updatedAt',
            ],
            'hasNotKey' => [
                'imageFile',
            ],
        ];

        $this->testSuccess(
            Request::METHOD_GET,
            $this->iri,
            [
                'auth_bearer' => $adminToken,
            ],
            Response::HTTP_OK,
            [
                BaseTest::ASSERTION_TYPE['SERIALIZATION'] => $assertSerialization,
            ],
        );
    }

    public static function provideUpdateCatalogProductSuccess(): Generator
    {
        $fakeData = self::getFakeDataCatalogProduct();
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];

        $assertSerialization = [
            'hasKey' => [
                'id',
                'title',
                'subtitle',
                'description',
                'price',
                'slug',
                'imageUrl',
                ['category' => ['id', 'title']],
                'createdAt',
                'updatedAt',
            ],
            'hasNotKey' => [
                'imageFile',
            ],
        ];

        yield 'Full' => [
            [
                'auth_bearer' => $adminToken,
                'headers' => [
                    'Content-Type' => 'application/merge-patch+json',
                ],
                'json' => [
                    'title' => $fakeData['title'],
                    'subtitle' => $fakeData['subtitle'],
                    'description' => $fakeData['description'],
                    'price' => $fakeData['price'],
                ],
            ],
            [
                BaseTest::ASSERTION_TYPE['SERIALIZATION'] => $assertSerialization,
                BaseTest::ASSERTION_TYPE['EQUAL'] => [
                    'title' => $fakeData['title'],
                    'subtitle' => $fakeData['subtitle'],
                    'description' => $fakeData['description'],
                    'price' => $fakeData['price'],
                ],
            ],
        ];

        yield 'Partial: title only' => [
            [
                'auth_bearer' => $adminToken,
                'headers' => [
                    'Content-Type' => 'application/merge-patch+json',
                ],
                'json' => [
                    'title' => $fakeData['title'],
                ],
            ],
            [
                BaseTest::ASSERTION_TYPE['SERIALIZATION'] => $assertSerialization,
                BaseTest::ASSERTION_TYPE['EQUAL'] => [
                    'title' => $fakeData['title'],
                ],
            ],
        ];
    }

    #[DataProvider('provideUpdateCatalogProductSuccess')]
    public function testUpdateCatalogProductSuccess(
        array $options,
        array $asserts,
    ): void {
        $this->testSuccess(
            Request::METHOD_PATCH,
            $this->iri,
            $options,
            Response::HTTP_OK,
            $asserts,
        );
    }

    public static function provideUpdateProductException(): Generator
    {
        $memberToken = self::PLACEHOLDERS['TOKENS']['MEMBER'];

        yield 'No role' => [
            [
                'headers' => [
                    'Content-Type' => 'application/merge-patch+json',
                ],
                'json' => [
                    'title' => 'Updated title',
                ],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];

        yield 'Not admin' => [
            [
                'auth_bearer' => $memberToken,
                'headers' => [
                    'Content-Type' => 'application/merge-patch+json',
                ],
                'json' => [
                    'title' => 'Updated title',
                ],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_FORBIDDEN,
                'message' => 'Access Denied',
            ],
        ];
    }

    #[DataProvider('provideUpdateProductException')]
    public function testUpdateProductException(
        array $options,
        array $exception,
    ): void {
        $this->testException(Request::METHOD_PATCH, $this->iri, $options, $exception);
    }

    public static function provideDeleteCatalogProductSuccess(): Generator
    {
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];

        yield 'Full: Admin' => [
            [
                'auth_bearer' => $adminToken,
            ],
        ];
    }

    #[DataProvider('provideDeleteCatalogProductSuccess')]
    public function testDeleteCatalogProductSuccess(
        array $options,
    ): void {
        $this->testSuccess(
            Request::METHOD_DELETE,
            $this->iri,
            $options,
            Response::HTTP_NO_CONTENT,
        );
    }

    public static function provideDeleteCatalogProductException(): Generator
    {
        $memberToken = self::PLACEHOLDERS['TOKENS']['MEMBER'];

        yield 'No role' => [
            [],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];

        yield 'Not admin' => [
            [
                'auth_bearer' => $memberToken,
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_FORBIDDEN,
                'message' => 'Access Denied',
            ],
        ];
    }

    #[DataProvider('provideDeleteCatalogProductException')]
    public function testDeleteCatalogProductException(
        array $options,
        array $exception,
    ): void {
        $this->testException(Request::METHOD_DELETE, $this->iri, $options, $exception);
    }

    public static function provideProductNotFoundException(): Generator
    {
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];

        yield 'Get not found' => [
            Request::METHOD_GET,
            self::URL_API_OPE . '/' . self::UNKNOWN_ID,
            [],
        ];
        yield 'Patch not found' => [
            Request::METHOD_PATCH,
            self::URL_API_OPE . '/' . self::UNKNOWN_ID,
            [
                'auth_bearer' => $adminToken,
                'headers' => [
                    'Content-Type' => 'application/merge-patch+json',
                ],
                'json' => [
                    'title' => 'Updated title',
                ],
            ],
        ];
        yield 'Delete not found' => [
            Request::METHOD_DELETE,
            self::URL_API_OPE . '/' . self::UNKNOWN_ID,
            [
                'auth_bearer' => $adminToken,
            ],
        ];
        yield 'Image not found' => [
            Request::METHOD_POST,
            self::URL_API_OPE . '/' . self::UNKNOWN_ID . '/image',
            [
                'auth_bearer' => $adminToken,
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'extra' => [
                    'files' => [
                        'imageFile' => self::PLACEHOLDERS['IMAGES']['PAYSAGE'],
                    ],
                ],
            ],
        ];
        yield 'Get invalid id' => [
            Request::METHOD_GET,
            self::URL_API_OPE . '/' . self::INVALID_ID,
            [],
        ];
        yield 'Patch invalid id' => [
            Request::METHOD_PATCH,
            self::URL_API_OPE . '/' . self::INVALID_ID,
            [
                'auth_bearer' => $adminToken,
                'headers' => [
                    'Content-Type' => 'application/merge-patch+json',
                ],
                'json' => [
                    'title' => 'Updated title',
                ],
            ],
        ];
        yield 'Delete invalid id' => [
            Request::METHOD_DELETE,
            self::URL_API_OPE . '/' . self::INVALID_ID,
            [
                'auth_bearer' => $adminToken,
            ],
        ];
        yield 'Image invalid id' => [
            Request::METHOD_POST,
            self::URL_API_OPE . '/' . self::INVALID_ID . '/image',
            [
                'auth_bearer' => $adminToken,
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'extra' => [
                    'files' => [
                        'imageFile' => self::PLACEHOLDERS['IMAGES']['PAYSAGE'],
                    ],
                ],
            ],
        ];
    }

    #[DataProvider('provideProductNotFoundException')]
    public function testProductNotFoundException(
        string $method,
        string $uri,
        array $options,
    ): void {
        $this->testException($method, $uri, $options, [
            'class' => ClientExceptionInterface::class,
            'code' => Response::HTTP_NOT_FOUND,
        ]);
    }

    public static function provideUploadImageProductSuccess(): Generator
    {
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];
        $image = self::PLACEHOLDERS['IMAGES']['PAYSAGE'];

        yield 'Upload Image' => [
            [
                'auth_bearer' => $adminToken,
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'extra' => [
                    'files' => [
                        'imageFile' => $image,
                    ],
                ],
            ],
        ];
    }

    #[DataProvider('provideUploadImageProductSuccess')]
    public function testUploadImageProductSuccess(
        array $options,
    ): void {
        $this->testSuccess(
            Request::METHOD_POST,
            $this->iri . '/image',
            $options,
            Response::HTTP_CREATED,
        );
    }

    public static function provideUploadImageProductException(): Generator
    {
        $adminToken = self::PLACEHOLDERS['TOKENS']['ADMIN'];
        $memberToken = self::PLACEHOLDERS['TOKENS']['MEMBER'];

        yield 'No role' => [
            [
                'extra' => [
                    'files' => [
                        'imageFile' => self::PLACEHOLDERS['IMAGES']['PAYSAGE'],
                    ],
                ],
                'headers' => ['Content-Type' => 'multipart/form-data'],
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'HTTP 401 returned',
            ],
        ];

        yield 'Not admin' => [
            [
                'extra' => [
                    'files' => [
                        'imageFile' => self::PLACEHOLDERS['IMAGES']['PAYSAGE'],
                    ],
                ],
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'auth_bearer' => $memberToken,
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_FORBIDDEN,
                'message' => 'Access Denied',
            ],
        ];

        yield 'Missing file' => [
            [
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'auth_bearer' => $adminToken,
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'imageFile: This value should not be blank.',
            ],
        ];

        yield 'Wrong content type header' => [
            [
                'extra' => [
                    'files' => [
                        'imageFile' => self::PLACEHOLDERS['IMAGES']['PAYSAGE'],
                    ],
                ],
                'headers' => ['Content-Type' => 'application/json'],
                'auth_bearer' => $adminToken,
            ],
            [
                'class' => ClientExceptionInterface::class,
                'code' => Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'message' => 'The content-type "application/json" is not supported.',
            ],
        ];
    }

    #[DataProvider('provideUploadImageProductException')]
    public function testUploadImageProductException(
        array $options,
        array $exception,
    ): void {
        $this->testException(
            Request::METHOD_POST,
            $this->iri . '/image',
            $options,
            $exception
        );
    }

    public function testUploadImageProductRejectsGifContent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'product-gif-');
        if (false === $path) {
            self::fail('Unable to create temporary GIF upload.');
        }

        file_put_contents($path, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));
        $file = new UploadedFile($path, 'product.gif', 'image/gif', null, true);

        try {
            $this->testException(
                Request::METHOD_POST,
                $this->iri . '/image',
                [
                    'auth_bearer' => self::PLACEHOLDERS['TOKENS']['ADMIN'],
                    'headers' => ['Content-Type' => 'multipart/form-data'],
                    'extra' => ['files' => ['imageFile' => $file]],
                ],
                [
                    'class' => ClientExceptionInterface::class,
                    'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'message' => 'Invalid product image file type: image/gif.',
                ],
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private static function getFakeDataCatalogProduct(): array
    {
        $faker = Factory::create();

        return [
            'title' => $faker->sentence(3),
            'subtitle' => $faker->sentence(5),
            'description' => $faker->text(),
            'price' => $faker->randomFloat(2, 10, 500),
        ];
    }
}
