<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Livrable du jalon 1 : prouver que le service authentifie un porteur de token
 * sans acceder a la base de l'emetteur, et qu'il rejette ce qui doit l'etre.
 */
final class PingTest extends WebTestCase
{
    private const string USER_ID = '3f1c9b2a-7d84-4e51-9a6c-8b2e5d0f1a73';

    public function testHealthIsPubliclyReachable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'ok', 'service' => 'service_shop'],
            $this->decode($client),
        );
    }

    public function testPingWithoutTokenIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/ping');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPingWithValidTokenReturnsIdentityFromClaims(): void
    {
        $client = self::createClient();

        $client->request('GET', '/ping', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->encodeToken(expiresIn: 300),
        ]);

        self::assertResponseIsSuccessful();

        $payload = $this->decode($client);
        self::assertSame('service_shop', $payload['service']);
        self::assertSame(self::USER_ID, $payload['userId']);
        self::assertSame(['ROLE_ADMIN'], $payload['roles']);
    }

    public function testPingWithExpiredTokenIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/ping', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->encodeToken(expiresIn: -10),
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testPingWithTamperedSignatureIsRejected(): void
    {
        $client = self::createClient();

        // Un caractere altere dans la signature : le token reste bien forme,
        // seule la verification cryptographique doit le rejeter.
        $token = $this->encodeToken(expiresIn: 300);
        $tampered = substr($token, 0, -1) . ('A' === substr($token, -1) ? 'B' : 'A');

        $client->request('GET', '/ping', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tampered,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    private function encodeToken(int $expiresIn): string
    {
        $now = time();

        $encoder = self::getContainer()->get('test.jwt_encoder');
        self::assertInstanceOf(JWTEncoderInterface::class, $encoder);

        return $encoder->encode([
            'sub' => self::USER_ID,
            'roles' => ['ROLE_ADMIN'],
            'iat' => $now,
            'exp' => $now + $expiresIn,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
