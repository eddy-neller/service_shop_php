<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Transport;

use AsyncAws\Core\HttpClient\AwsRetryStrategy;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Donne au transport SQS des requêtes **bornées dans le temps**.
 *
 * FrameworkBundle construit `AmazonSqsTransportFactory` sans client HTTP : AsyncAws crée alors le
 * sien, sans `max_duration`. Une connexion coupée sans réponse — réseau partitionné, interface
 * retirée — reste donc ouverte indéfiniment. Les 300 s de `default_socket_timeout` ne la sauvent pas :
 * le transport interroge sa réponse par `resolve(0.1)`, et ce délai remplace celui de la requête,
 * si bien que l'inactivité ne s'accumule jamais. Mesuré le 2026-10-24 : worker détaché du réseau du
 * bus, aucun avertissement en dix minutes, et aucune reprise au retour du bus ; seul
 * `--time-limit=3600` l'aurait relancé, jusqu'à une heure plus tard.
 *
 * Avec `max_duration`, curl abandonne la requête : la connexion morte devient une `NetworkException`,
 * que `BusOutageTolerantTransport` reconnaît, journalise et attend.
 *
 * ## Ce que ce client doit reprendre d'AsyncAws
 *
 * Fournir un client retire celui d'AsyncAws, **et son `RetryableHttpClient`** : 3 nouvelles tentatives
 * sur une erreur réseau, une 5xx ou un throttling, avant que l'erreur ne remonte. Il est reconstruit
 * ici à l'identique (`AwsRetryStrategy` par défaut, 3 tentatives). Le pire cas d'une partition est
 * donc de quatre requêtes de 30 s, soit environ deux minutes avant le premier avertissement.
 */
#[AsDecorator(decorates: 'messenger.transport.sqs.factory')]
final readonly class BoundedSqsTransportFactory implements TransportFactoryInterface
{
    /**
     * Au-dessus des 20 s du long polling (`wait_time` par défaut du transport), qu'une réponse saine
     * ne dépasse pas. Allonger `wait_time` impose de relever cette borne, sinon chaque attente de
     * message deviendrait une panne.
     */
    public const int MAX_REQUEST_SECONDS = 30;

    private const int MAX_RETRIES = 3;

    private AmazonSqsTransportFactory $factory;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(service: 'monolog.logger.messenger')]
        LoggerInterface $logger,
    ) {
        $this->factory = new AmazonSqsTransportFactory($logger, new RetryableHttpClient(
            $httpClient->withOptions(['max_duration' => self::MAX_REQUEST_SECONDS]),
            new AwsRetryStrategy(),
            self::MAX_RETRIES,
            $logger,
        ));
    }

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return $this->factory->createTransport($dsn, $options, $serializer);
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return $this->factory->supports($dsn, $options);
    }
}
