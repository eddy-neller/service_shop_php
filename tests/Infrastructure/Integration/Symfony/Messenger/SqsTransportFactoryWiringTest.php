<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Symfony\Messenger;

use App\Infrastructure\Symfony\Messenger\Transport\BoundedSqsTransportFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le transport SQS est bien construit par `BoundedSqsTransportFactory`.
 *
 * Elle remplace la fabrique de FrameworkBundle par décoration, et ses tests unitaires l'instancient à
 * la main : aucun ne verrait une décoration perdue — un identifiant renommé dans une version de
 * Symfony, un attribut retiré. Le transport retrouverait alors des requêtes sans borne, et le
 * consommateur pourrait de nouveau attendre en silence une réponse qui ne vient pas.
 */
final class SqsTransportFactoryWiringTest extends KernelTestCase
{
    public function testTheContainerBuildsSqsTransportsWithBoundedRequests(): void
    {
        self::assertInstanceOf(BoundedSqsTransportFactory::class, self::getContainer()->get('messenger.transport.sqs.factory'));
    }
}
