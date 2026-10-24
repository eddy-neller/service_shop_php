<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Transport;

use App\Infrastructure\Symfony\Messenger\Transport\BoundedSqsTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class BoundedSqsTransportFactoryTest extends TestCase
{
    private const string DSN = 'sqs://aws-local:4566/shop-user-events?sslmode=disable&auto_setup=false&region=eu-west-3&access_key=k&secret_key=s';

    /**
     * Chaque requete du transport porte la borne : la resolution de l'URL de la file comme la
     * reception. Sans elle, une connexion coupee sans reponse ne rend jamais la main, et le
     * consommateur attend en silence.
     */
    public function testEverySqsRequestIsBoundedInTime(): void
    {
        $maxDurations = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$maxDurations): MockResponse {
            $maxDurations[] = $options['max_duration'];

            return new MockResponse(str_contains($options['normalized_headers']['x-amz-target'][0] ?? '', 'GetQueueUrl')
                ? '{"QueueUrl":"http://aws-local:4566/000000000000/shop-user-events"}'
                : '{"Messages":[]}', ['response_headers' => ['content-type: application/x-amz-json-1.0']]);
        });

        $transport = (new BoundedSqsTransportFactory($client, new NullLogger()))
            ->createTransport(self::DSN, [], $this->createStub(SerializerInterface::class));
        iterator_to_array($transport->get());

        self::assertSame([30.0, 30.0], $maxDurations);
    }

    public function testItHandlesSqsDsnsOnly(): void
    {
        $factory = new BoundedSqsTransportFactory(new MockHttpClient(), new NullLogger());

        self::assertTrue($factory->supports(self::DSN, []));
        self::assertFalse($factory->supports('in-memory://', []));
    }
}
