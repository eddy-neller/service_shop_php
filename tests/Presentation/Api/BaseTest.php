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
            'PAYSAGE' => 'PAYSAGE_IMAGE_PLACEHOLDER',
            'AVATAR' => 'AVATAR_IMAGE_PLACEHOLDER',
        ],
    ];

    private const array TOKEN_PLACEHOLDER_MAPPING = [
        'ADMIN_TOKEN_PLACEHOLDER' => 'user_admin',
        'MEMBER_TOKEN_PLACEHOLDER' => 'user_member',
        'MEMBER_1_TOKEN_PLACEHOLDER' => 'user_member_1',
    ];

    private const array IMAGE_PLACEHOLDER_MAPPING = [
        'PAYSAGE_IMAGE_PLACEHOLDER' => 'paysage.jpg',
        'AVATAR_IMAGE_PLACEHOLDER' => 'venom.jpg',
    ];

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

    protected function getImage(string $filename, string $suffix): UploadedFile
    {
        return $this->getPhysicalTempFile($filename, $suffix);
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

        if (isset($options['extra']['files']['avatarFile']) && is_string($options['extra']['files']['avatarFile'])) {
            $options['extra']['files']['avatarFile'] = $this->replaceImagePlaceholder($options['extra']['files']['avatarFile']);
        }

        return $options;
    }

    private function replaceImagePlaceholder(string $placeholder): UploadedFile|string
    {
        if (!isset(self::IMAGE_PLACEHOLDER_MAPPING[$placeholder])) {
            return $placeholder;
        }

        $filename = self::IMAGE_PLACEHOLDER_MAPPING[$placeholder];

        return $this->getImage($filename, __METHOD__);
    }

    private function getPhysicalTempFile(string $filename, string $suffix): UploadedFile
    {
        $dir = 'images';

        $cleanSuffix = explode('::', $suffix)[1];

        $tmpFilePath = sys_get_temp_dir() . '/' . $cleanSuffix . '.jpg';

        copy(
            static::getContainer()->getParameter('kernel.project_dir') . '/assets/tests/' . $dir . '/' . $filename,
            $tmpFilePath
        );

        return new UploadedFile($tmpFilePath, $filename);
    }
}
