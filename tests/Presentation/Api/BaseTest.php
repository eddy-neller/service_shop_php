<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ODM\MongoDB\DocumentManager;
use Faker\Factory;
use Faker\Generator;
use JsonException;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTEncodeFailureException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @SuppressWarnings("PMD")
 */
abstract class BaseTest extends ApiTestCase
{
    protected const string URL_API = '/api/';

    public const array ASSERTION_TYPE = [
        'SERIALIZATION' => 'serialization',
        'EQUAL' => 'equals',
        'NULL' => 'null',
        'NOT_NULL' => 'notNull',
        'DATE' => 'date',
        'TRANSLATION' => 'translation',
        'IRI' => 'iri',
        'EMPTY' => 'empty',
        'PAGINATION' => 'pagin',
        'FILTER' => 'filter',
    ];

    protected const int PAGIN_IPP = 2;

    protected const int PAGIN_PAGE_ONE = 1;

    protected const int PAGIN_PAGE = 3;

    protected const array MEDIA_TYPE = [
        'IMAGE' => 'IMAGE',
    ];

    protected const array PLACEHOLDERS = [
        'TOKENS' => [
            'ADMIN' => 'ADMIN_TOKEN_PLACEHOLDER',
            'MEMBER' => 'MEMBER_TOKEN_PLACEHOLDER',
            'MEMBER_1' => 'MEMBER_1_TOKEN_PLACEHOLDER',
        ],
        'IMAGES' => [
            // Acceptees
            'PRODUCT' => 'PRODUCT_IMAGE_PLACEHOLDER',
            'PRODUCT_PNG' => 'PRODUCT_PNG_IMAGE_PLACEHOLDER',
            'PRODUCT_WEBP' => 'PRODUCT_WEBP_IMAGE_PLACEHOLDER',
            'PRODUCT_MIN_DIMENSION' => 'PRODUCT_MIN_DIMENSION_IMAGE_PLACEHOLDER',
            'PRODUCT_MAX_DIMENSION' => 'PRODUCT_MAX_DIMENSION_IMAGE_PLACEHOLDER',
            'PRODUCT_MAX_SIZE' => 'PRODUCT_MAX_SIZE_IMAGE_PLACEHOLDER',
            'PNG_NAMED_JPG' => 'PNG_NAMED_JPG_IMAGE_PLACEHOLDER',
            // Refusees : format
            'GIF' => 'GIF_IMAGE_PLACEHOLDER',
            'SVG' => 'SVG_IMAGE_PLACEHOLDER',
            'PDF' => 'PDF_IMAGE_PLACEHOLDER',
            'TEXT_NAMED_JPG' => 'TEXT_NAMED_JPG_IMAGE_PLACEHOLDER',
            'EMPTY' => 'EMPTY_IMAGE_PLACEHOLDER',
            'TRUNCATED' => 'TRUNCATED_IMAGE_PLACEHOLDER',
            // Refusees : poids
            'OVER_MAX_SIZE' => 'OVER_MAX_SIZE_IMAGE_PLACEHOLDER',
            // Refusees : dimensions
            'TOO_NARROW' => 'TOO_NARROW_IMAGE_PLACEHOLDER',
            'TOO_SHORT' => 'TOO_SHORT_IMAGE_PLACEHOLDER',
            'TOO_WIDE' => 'TOO_WIDE_IMAGE_PLACEHOLDER',
            'TOO_TALL' => 'TOO_TALL_IMAGE_PLACEHOLDER',
            'TOO_LARGE' => 'TOO_LARGE_IMAGE_PLACEHOLDER',
        ],
    ];

    private const array TOKEN_PLACEHOLDER_MAPPING = [
        'ADMIN_TOKEN_PLACEHOLDER' => 'user_admin',
        'MEMBER_TOKEN_PLACEHOLDER' => 'user_member',
        'MEMBER_1_TOKEN_PLACEHOLDER' => 'user_member_1',
    ];

    /**
     * `file` : fichier de `tests/Fixtures/images/` · `name` : nom annonce par le client
     * (le serveur doit l'ignorer) · `size` : poids exact, atteint en gonflant le JPEG.
     *
     * Les poids ne sont pas versionnes : 10 Mo par fichier alourdiraient le depot pour un
     * octet de difference. Ils suivent `PRODUCT_IMAGE_MAX_SIZE` (10 485 760, `.env.test`),
     * que l'`upload_max_filesize` du `php.ini` doit depasser — sinon PHP refuse le fichier
     * avant le validateur, et le cas « un octet de trop » ne teste plus le bon message.
     *
     * @var array<string, array{file: string, name?: string, size?: int}>
     */
    private const array IMAGE_PLACEHOLDER_MAPPING = [
        'PRODUCT_IMAGE_PLACEHOLDER' => ['file' => 'product.jpg'],
        'PRODUCT_PNG_IMAGE_PLACEHOLDER' => ['file' => 'product.png'],
        'PRODUCT_WEBP_IMAGE_PLACEHOLDER' => ['file' => 'product.webp'],
        'PRODUCT_MIN_DIMENSION_IMAGE_PLACEHOLDER' => ['file' => 'product-200x200.jpg'],
        'PRODUCT_MAX_DIMENSION_IMAGE_PLACEHOLDER' => ['file' => 'product-2000x2000.jpg'],
        'PRODUCT_MAX_SIZE_IMAGE_PLACEHOLDER' => ['file' => 'product.jpg', 'size' => 10_485_760],
        'PNG_NAMED_JPG_IMAGE_PLACEHOLDER' => ['file' => 'product.png', 'name' => 'product.jpg'],
        'GIF_IMAGE_PLACEHOLDER' => ['file' => 'product.gif'],
        'SVG_IMAGE_PLACEHOLDER' => ['file' => 'product.svg'],
        'PDF_IMAGE_PLACEHOLDER' => ['file' => 'document.pdf'],
        'TEXT_NAMED_JPG_IMAGE_PLACEHOLDER' => ['file' => 'not-an-image.txt', 'name' => 'product.jpg'],
        'EMPTY_IMAGE_PLACEHOLDER' => ['file' => 'empty.jpg'],
        'TRUNCATED_IMAGE_PLACEHOLDER' => ['file' => 'truncated.jpg'],
        'OVER_MAX_SIZE_IMAGE_PLACEHOLDER' => ['file' => 'product.jpg', 'size' => 10_485_761],
        'TOO_NARROW_IMAGE_PLACEHOLDER' => ['file' => 'product-199x200.jpg'],
        'TOO_SHORT_IMAGE_PLACEHOLDER' => ['file' => 'product-200x199.jpg'],
        'TOO_WIDE_IMAGE_PLACEHOLDER' => ['file' => 'product-2001x200.jpg'],
        'TOO_TALL_IMAGE_PLACEHOLDER' => ['file' => 'product-200x2001.jpg'],
        'TOO_LARGE_IMAGE_PLACEHOLDER' => ['file' => 'product-2400x1800.jpg'],
    ];

    /**
     * Fichiers de test versionnes, generes sans bibliotheque d'image (l'image `ci`
     * n'embarque pas GD). `product.jpg` fait 256 px de cote, entre les bornes de `.env.test`.
     */
    private const string FIXTURE_IMAGE_DIR = __DIR__ . '/../../Fixtures/images';

    /** Taille maximale de la charge utile d'un segment JPEG COM (65 535 moins les 2 octets de longueur). */
    private const int JPEG_COMMENT_MAX_PAYLOAD = 65_533;

    protected Client $client;

    protected Generator $faker;

    protected DocumentManager $documentManager;

    protected string $userAdmin = 'user_admin';

    protected string $userModer = 'user_moder';

    protected string $userMember = 'user_member';

    /**
     * Roles portes par le token forge pour chaque utilisateur fictif.
     *
     * Il n'existe aucun compte : ce service ne connait de l'utilisateur que les claims
     * `sub` et `roles`, reconstruits par `JwtAuthenticatedUser`. Un « utilisateur » de
     * test n'est donc rien d'autre qu'un couple UUID / liste de roles.
     */
    private const array USER_ROLES = [
        'user_admin' => ['ROLE_ADMIN'],
        'user_moder' => ['ROLE_MODERATEUR'],
        'user_member' => ['ROLE_USER'],
        // Second membre ordinaire : sert aux cas « pas proprietaire », qui verifient qu'un
        // utilisateur authentifie ne peut pas atteindre l'adresse d'un autre.
        'user_member_1' => ['ROLE_USER'],
    ];

    /** Les index du mapping ne sont poses qu'une fois par processus (cf. resetDatabase()). */
    private static bool $indexesEnsured = false;

    /** @var list<string> Copies temporaires creees par getImage(), supprimees en tearDown(). */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->faker = Factory::create();
        $this->client = self::createClient();
        $documentManager = static::getContainer()->get(DocumentManager::class);
        if (!$documentManager instanceof DocumentManager) {
            throw new RuntimeException('MongoDB document manager not found');
        }

        $this->documentManager = $documentManager;

        $this->resetDatabase();
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    abstract protected function seedTestData(): void;

    protected function getApiClient(): HttpClientInterface
    {
        return $this->client ?? self::createClient();
    }

    protected function getFaker(): Generator
    {
        return $this->faker ?? Factory::create();
    }

    protected function getManager(): DocumentManager
    {
        return $this->documentManager ?? static::getContainer()->get(DocumentManager::class);
    }

    protected function request(string $method, string $url, array $options = []): ?ResponseInterface
    {
        try {
            if (!isset($options['headers'])) {
                $options['headers'] = [];
            }

            if (!isset($options['headers']['Accept'])) {
                $options['headers']['Accept'] = 'application/json';
            }

            return $this->getApiClient()->request($method, $url, $options);
        } catch (TransportExceptionInterface) {
            return null;
        }
    }

    /**
     * Forge un token localement, sans que le service emetteur tourne.
     *
     * C'est la contrepartie de l'autonomie exigee par AGENTS.md : la suite ne peut pas
     * dependre d'un appel a un endpoint de login, sinon `GET /health`
     * serait la seule chose que ce depot sait tester seul. La signature utilise la paire
     * versionnee `config/jwt/test/`, declaree sous `when@test` uniquement — dev et prod
     * restent structurellement incapables d'emettre.
     */
    protected function getToken(string $username, ?int $expiresAt = null): string
    {
        if (!isset(self::USER_ROLES[$username])) {
            throw new RuntimeException(sprintf('getToken: unknown test user "%s".', $username));
        }

        $encoder = static::getContainer()->get('test.jwt_encoder');
        if (!$encoder instanceof JWTEncoderInterface) {
            throw new RuntimeException('getToken: JWT encoder not found');
        }

        try {
            return $encoder->encode([
                // `user_id_claim: sub`, et `JwtAuthenticatedUser` exige un UUID valide.
                'sub' => $this->userIdOf($username),
                'roles' => self::USER_ROLES[$username],
                'exp' => $expiresAt ?? time() + 900,
            ]);
        } catch (JWTEncodeFailureException $e) {
            throw new RuntimeException('getToken: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * UUID stable par utilisateur : deux appels dans un meme test doivent produire la
     * meme identite, sans quoi un scenario multi-requetes deviendrait incoherent.
     */
    /**
     * Le `sub` du jeton de test. Ce service n'ayant aucun compte, c'est **la seule**
     * identite d'utilisateur qui existe : les seeders Customer l'emploient comme
     * `userAccountId` du client correspondant.
     */
    protected function userIdOf(string $username): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, $username)->toString();
    }

    protected function testSuccess(
        string $method,
        string $uri,
        array $options,
        int $code,
        array $asserts = [],
        bool $noTreatment = false,
    ): ?array {
        $options = $this->replacePlaceholders($options);

        $res = $this->request(
            $method,
            $uri,
            $options
        );

        try {
            $statusCode = $res->getStatusCode();
            $result = [];

            if (!in_array($statusCode, [204, 205], true)) {
                $content = $res->getContent();
                $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (
            ClientExceptionInterface|RedirectionExceptionInterface|
            ServerExceptionInterface|TransportExceptionInterface|JsonException $e
        ) {
            throw new RuntimeException('testSuccess: invalid response: ' . $e->getMessage());
        }

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame($code);

        if (!$noTreatment) {
            if (Request::METHOD_DELETE !== $method) {
                $isCollection = $this->isCollectionResponse($result);
                $res = $this->getTestResult($result, $isCollection);
                $this->makeAssertion($res, $asserts);

                return $res;
            }

            return null;
        }

        return $this->getTestResult($result);
    }

    protected function testException(
        string $method,
        string $uri,
        array $options,
        array $exception,
    ): void {
        $this->expectException($exception['class']);
        $this->expectExceptionCode($exception['code']);

        if (null !== $exception['message']) {
            $this->expectExceptionMessage($exception['message']);
        }

        $options = $this->replacePlaceholders($options);

        $response = $this->request(
            $method,
            $uri,
            $options
        );

        json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function makeAssertion(
        array $res,
        array $asserts,
    ): void {
        foreach ($asserts as $type => $assert) {
            switch ($type) {
                case self::ASSERTION_TYPE['SERIALIZATION']:
                    foreach ($assert as $typeSerialization => $value) {
                        foreach ($value as $val) {
                            $this->analyseSerializeAssertion($val, $res, $typeSerialization);
                        }
                    }

                    break;
                case self::ASSERTION_TYPE['EQUAL']:
                    foreach ($assert as $key => $value) {
                        $this->assertEquals($res[$key], $value);
                    }

                    break;
                case self::ASSERTION_TYPE['NULL']:
                    foreach ($assert as $value) {
                        $this->assertNull($res[$value]);
                    }

                    break;
                case self::ASSERTION_TYPE['NOT_NULL']:
                    foreach ($assert as $value) {
                        $this->assertNotNull($res[$value]);
                    }

                    break;
                case self::ASSERTION_TYPE['DATE']:
                case self::ASSERTION_TYPE['TRANSLATION']:
                    foreach ($assert as $key => $value) {
                        $this->assertStringContainsString($value, (string) $res[$key]);
                    }

                    break;
                case self::ASSERTION_TYPE['EMPTY']:
                    $this->assertEmpty($res);
                    break;
                case self::ASSERTION_TYPE['PAGINATION']:
                case self::ASSERTION_TYPE['FILTER']:
                    break;
            }
        }
    }

    protected function switchKeySerialization(array $array, array $unwantedKeys): array
    {
        $hasKey = $array['hasKey'] ?? [];
        $hasNotKey = $array['hasNotKey'] ?? [];

        foreach ($unwantedKeys as $unwantedKey) {
            $keyIndex = array_search($unwantedKey, $hasKey, true);
            if (false !== $keyIndex) {
                unset($hasKey[$keyIndex]);
                $hasNotKey[] = $unwantedKey;
            }
        }

        $array['hasKey'] = array_values($hasKey);
        $array['hasNotKey'] = array_values($hasNotKey);

        return $array;
    }

    protected function getInstance(mixed $class, array $criteria): mixed
    {
        $this->getManager()->clear();

        return $this->getManager()->getRepository($class)->findOneBy($criteria); // @phpstan-ignore-line
    }

    /**
     * Rend une copie jetable de l'image : le fichier versionne ne doit jamais etre
     * deplace ni modifie par le code sous test. Le MIME est deduit du contenu, d'ou
     * l'absence d'extension sur le nom temporaire.
     */
    protected function getImage(string $filename, ?string $clientName = null, ?int $size = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'shop-api-test-image-');
        if (false === $path) {
            throw new RuntimeException('getImage: unable to create a temporary file');
        }

        $this->temporaryFiles[] = $path;

        if (!copy(self::FIXTURE_IMAGE_DIR . '/' . $filename, $path)) {
            throw new RuntimeException(sprintf('getImage: unable to copy fixture image "%s"', $filename));
        }

        if (null !== $size) {
            $this->inflateJpeg($path, $size);
        }

        return new UploadedFile($path, $clientName ?? $filename);
    }

    /**
     * Vide les collections **sans** les supprimer.
     *
     * `drop()` emporterait les index avec les documents, et il faudrait les reposer a
     * chaque test : mesure faite, `drop()` + `ensureIndexes()` coute **155 ms** la, quand
     * un `deleteMany()` en coute **0,8**. Sur 59 tests API, c'est neuf secondes de suite
     * passees a reconstruire huit index identiques.
     *
     * Les index doivent malgre tout exister — sans eux, les tests de conflit de titre ne
     * rejetteraient plus rien et passeraient au vert pour une mauvaise raison. Ils sont
     * donc poses une seule fois par processus PHPUnit, et `deleteMany()` les preserve.
     */
    protected function resetDatabase(): void
    {
        $database = $this->documentManager->getClient()
            ->selectDatabase($_ENV['MONGODB_DB'] ?? 'service_shop_test');

        if (!self::$indexesEnsured) {
            $this->documentManager->getSchemaManager()->ensureIndexes();
            self::$indexesEnsured = true;
        }

        foreach (['product', 'category', 'customer', 'cart', 'domain_event_outbox'] as $collection) {
            $database->selectCollection($collection)->deleteMany([]);
        }

        $this->documentManager->clear();
    }

    protected function getIdFromIri(string $iri): string
    {
        return array_reverse(explode('/', $iri))[0];
    }

    protected static function generateQuery(
        array $options = [],
    ): array {
        $query = [];

        // page
        if (array_key_exists('page', $options) && $options['page']) {
            $query['page'] = $options['page'];
        }

        // itemsPerPage
        if (array_key_exists('ipp', $options) && $options['ipp']) {
            $query['itemsPerPage'] = $options['ipp'];
        }

        // filters
        if (array_key_exists('filters', $options) && !empty($options['filters'])) {
            foreach ($options['filters'] as $value) {
                switch ($value['filter']) {
                    case 'exists':
                        $query['exists[' . $value['field'] . ']'] = $value['value'];
                        break;
                    case 'order':
                        $query['order[' . $value['field'] . ']'] = $value['sort'];
                        break;
                    case 'date':
                    case 'search':
                    case 'boolean':
                        $query[$value['field']] = $value['value'];
                        break;
                    case 'property':
                        foreach ($value['value'] as $v) {
                            $query['properties'][] = $v;
                        }

                        break;
                }
            }
        }

        return $query;
    }

    private function isCollectionResponse(array $res): bool
    {
        // Nouveau format paginé
        if (!array_key_exists('id', $res) && isset($res['items']) && is_array($res['items'])) {
            return array_is_list($res['items']) && isset($res['items'][0]['id']);
        }

        // Ancien format (liste brute)
        return array_is_list($res) && isset($res[0]['id']);
    }

    private function analyseSerializeAssertion(mixed $data, mixed $res, string $type): void
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    /* Assertion sur un subressource */
                    if ('hasKey' === $type) {
                        $this->serializeAssertion($key, $res, $type);
                    }

                    foreach ($value as $y) {
                        $this->analyseSerializeAssertion($y, $res[$key], $type);
                    }
                }
            }

            return;
        }

        $this->serializeAssertion($data, $res, $type);
    }

    private function serializeAssertion(mixed $data, mixed $res, string $type): void
    {
        switch ($type) {
            case 'hasKey':
                $this->assertArrayHasKey($data, $res);
                break;
            case 'hasNotKey':
                $this->assertArrayNotHasKey($data, $res);
                break;
        }
    }

    private function getTestResult(array $res, bool $onlyFirst = false): ?array
    {
        // Collection paginée (nouveau format avec 'items')
        if ($onlyFirst && isset($res['items']) && is_array($res['items'])) {
            $items = $res['items'];

            return $items[0] ?? null;
        }

        // Détection d'une collection simple (ancien format)
        if ($onlyFirst && [] !== $res && array_is_list($res)) {
            return $res[0];
        }

        // Sinon, renvoie tel quel (item unique ou vide)
        return $res;
    }

    private function replacePlaceholders(array $options): array
    {
        $options = $this->replaceTokenPlaceholders($options);

        return $this->replaceFilePlaceholders($options);
    }

    private function replaceTokenPlaceholders(array $options): array
    {
        if (!isset($options['auth_bearer'])) {
            return $options;
        }

        $tokenPlaceholder = $options['auth_bearer'];

        if (isset(self::TOKEN_PLACEHOLDER_MAPPING[$tokenPlaceholder])) {
            $username = self::TOKEN_PLACEHOLDER_MAPPING[$tokenPlaceholder];
            $options['auth_bearer'] = $this->getToken($username);
        }

        return $options;
    }

    private function replaceFilePlaceholders(array $options): array
    {
        if (isset($options['extra']['files']['imageFile']) && is_string($options['extra']['files']['imageFile'])) {
            $options['extra']['files']['imageFile'] = $this->replaceImagePlaceholder($options['extra']['files']['imageFile']);
        }

        return $options;
    }

    private function replaceImagePlaceholder(string $placeholder): UploadedFile|string
    {
        if (!isset(self::IMAGE_PLACEHOLDER_MAPPING[$placeholder])) {
            return $placeholder;
        }

        $image = self::IMAGE_PLACEHOLDER_MAPPING[$placeholder];

        return $this->getImage($image['file'], $image['name'] ?? null, $image['size'] ?? null);
    }

    /**
     * Porte un JPEG a un poids exact en inserant des segments de commentaire (COM) juste
     * apres le marqueur SOI. Les decodeurs les ignorent : l'image reste valide et garde ses
     * dimensions, seul le poids change.
     */
    private function inflateJpeg(string $path, int $size): void
    {
        $data = (string) file_get_contents($path);
        if (!str_starts_with($data, "\xFF\xD8")) {
            throw new RuntimeException('inflateJpeg: only a JPEG can be inflated');
        }

        $gap = $size - strlen($data);
        if ($gap < 0 || ($gap > 0 && $gap < 4)) {
            throw new RuntimeException(sprintf('inflateJpeg: cannot reach %d bytes from %d', $size, strlen($data)));
        }

        $segments = '';
        while ($gap > 0) {
            $segmentSize = min($gap, self::JPEG_COMMENT_MAX_PAYLOAD + 4);
            // Un reste de 1 a 3 octets ne tiendrait dans aucun segment : on le reporte.
            if ($gap - $segmentSize > 0 && $gap - $segmentSize < 4) {
                $segmentSize -= 4;
            }

            $payloadSize = $segmentSize - 4;
            $segments .= "\xFF\xFE" . pack('n', $payloadSize + 2) . str_repeat('A', $payloadSize);
            $gap -= $segmentSize;
        }

        file_put_contents($path, substr($data, 0, 2) . $segments . substr($data, 2));
    }
}
