<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Event\Integration\Handler;

use App\Infrastructure\Symfony\Messenger\Event\Integration\Handler\LogUnsupportedIntegrationEventHandler;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UnsupportedIntegrationEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LogUnsupportedIntegrationEventHandlerTest extends TestCase
{
    /**
     * Le message est acquitte : ce handler est donc la seule trace qu'un fait publie par
     * l'emetteur est passe sans etre traite.
     */
    public function testItWarnsWithWhatIdentifiesTheEventAtTheSender(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Unsupported integration event acknowledged', [
                'event_id' => '018f3a40-0000-7000-8000-000000000003',
                'type' => 'identity.user.renamed',
                'version' => 1,
            ]);

        (new LogUnsupportedIntegrationEventHandler($logger))(new UnsupportedIntegrationEvent(
            '018f3a40-0000-7000-8000-000000000003',
            'identity.user.renamed',
            1,
        ));
    }
}
