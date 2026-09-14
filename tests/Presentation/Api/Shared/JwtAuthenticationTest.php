<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Shared;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validation du JWT par le firewall, sur une vraie route protegee.
 *
 * `GET /api/shop/customers` exige `ROLE_ADMIN`. L'expression ne lisant pas l'objet, API Platform
 * l'evalue **avant** le provider : un token refuse n'atteint jamais MongoDB. D'ou l'absence de
 * `BaseTest` et de seeds, comme pour `HealthTest`.
 *
 * Le cas `ROLE_USER` -> 403 est le temoin : il prouve que les tokens forges ici sont acceptes, donc
 * que chaque 401 tient au defaut injecte, et non a un token que le test aurait mal fabrique.
 */
final class JwtAuthenticationTest extends ApiTestCase
{
    private const string PROTECTED_ROUTE = '/api/shop/customers';

    protected static ?bool $alwaysBootKernel = true;

    public function testMissingTokenIsUnauthorized(): void
    {
        $this->requestProtectedRoute(null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testValidTokenIsAuthenticated(): void
    {
        $this->requestProtectedRoute(fn (): string => $this->forgeToken(['roles' => ['ROLE_USER']]));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->requestProtectedRoute(
            fn (): string => $this->forgeToken(['roles' => ['ROLE_ADMIN'], 'exp' => time() - 60]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Le cas qui compte : un porteur s'accorde `ROLE_ADMIN` en reecrivant le payload, sans pouvoir
     * re-signer. Retoucher le dernier caractere de la signature ne prouverait rien a coup sur : ses
     * bits de poids faible sont du bourrage base64url, et le token pourrait rester valide.
     */
    public function testRewrittenPayloadIsRejected(): void
    {
        $this->requestProtectedRoute(function (): string {
            $parts = explode('.', $this->forgeToken(['roles' => ['ROLE_USER']]));

            $claims = json_decode($this->base64UrlDecode($parts[1]), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($claims);
            $claims['roles'] = ['ROLE_ADMIN'];
            $parts[1] = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

            return implode('.', $parts);
        });

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUnsignedTokenIsRejected(): void
    {
        $this->requestProtectedRoute(
            static fn (): string => self::base64UrlEncode(json_encode(['alg' => 'none', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
                . '.' . self::base64UrlEncode(json_encode([
                    'sub' => Uuid::uuid4()->toString(),
                    'roles' => ['ROLE_ADMIN'],
                    'exp' => time() + 900,
                ], JSON_THROW_ON_ERROR))
                . '.',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Correctement signes, mais mal formes : un refus d'authentification, jamais une erreur serveur.
     */
    public function testNonUuidSubjectIsRejected(): void
    {
        $this->requestProtectedRoute(
            fn (): string => $this->forgeToken(['sub' => 'not-a-uuid', 'roles' => ['ROLE_ADMIN']]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRolesThatAreNotAListAreRejected(): void
    {
        $this->requestProtectedRoute(fn (): string => $this->forgeToken(['roles' => 'ROLE_ADMIN']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Le token est fabrique apres `createClient()`, qui boote le noyau dont `forgeToken()` lit le
     * conteneur. `Accept: application/json` est explicite : le client de test d'API Platform envoie
     * `application/ld+json`, format que ce service n'expose pas — la route repondrait 406 des que
     * l'erreur n'est plus rendue par lexik (absence de token, 403).
     *
     * @param (callable(): string)|null $token
     */
    private function requestProtectedRoute(?callable $token): void
    {
        $client = self::createClient();
        $options = ['headers' => ['Accept' => 'application/json']];

        if (null !== $token) {
            $options['auth_bearer'] = $token();
        }

        $client->request('GET', self::PROTECTED_ROUTE, $options);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function forgeToken(array $claims): string
    {
        $encoder = self::getContainer()->get('test.jwt_encoder');

        if (!$encoder instanceof JWTEncoderInterface) {
            throw new RuntimeException('forgeToken: JWT encoder not found');
        }

        return $encoder->encode($claims + [
            'sub' => Uuid::uuid4()->toString(),
            'exp' => time() + 900,
        ]);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
