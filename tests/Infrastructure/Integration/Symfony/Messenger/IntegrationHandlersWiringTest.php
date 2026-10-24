<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Symfony\Messenger;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Chaque handler d'intégration se construit depuis le **vrai** conteneur.
 *
 * Les tests unitaires instancient les handlers à la main, avec la classe concrète qu'ils attendent :
 * ils ne voient pas ce que le conteneur injecte réellement. Or un handler du service déclare
 * `sign: true`, et Symfony décore alors le sérialiseur de chaque transport par `SigningSerializer`.
 * Un handler qui typait `UserIntegrationEventSerializer` compilait, passait ses tests, puis levait un
 * `TypeError` **à l'exécution**, que Messenger prenait pour un échec ordinaire. Seule la vérification
 * de bout en bout l'avait vu ; ce test le voit sans bus ni base.
 *
 * Les handlers sont **découverts** dans leur dossier, sans liste à tenir : un handler ajouté demain
 * est couvert sans qu'on y pense.
 */
final class IntegrationHandlersWiringTest extends KernelTestCase
{
    private const string HANDLERS_NAMESPACE = 'App\\Infrastructure\\Symfony\\Messenger\\Event\\Integration\\Handler\\';

    /**
     * @param class-string $handler
     */
    #[DataProvider('integrationHandlers')]
    public function testTheContainerCanBuildTheHandler(string $handler): void
    {
        self::assertInstanceOf($handler, self::getContainer()->get($handler));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function integrationHandlers(): iterable
    {
        $files = glob(\dirname(__DIR__, 5) . '/src/Infrastructure/Symfony/Messenger/Event/Integration/Handler/*Handler.php');
        self::assertNotFalse($files);
        self::assertNotEmpty($files, 'Aucun handler d integration trouve : le chemin a-t-il change ?');

        foreach ($files as $file) {
            $class = self::HANDLERS_NAMESPACE . basename($file, '.php');
            self::assertTrue(class_exists($class), $class);

            yield basename($file, '.php') => [$class];
        }
    }
}
