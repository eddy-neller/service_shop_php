<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\CQRS;

use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class HandledResultExtractor
{
    public function extract(Envelope $envelope): mixed
    {
        $handledStamps = $envelope->all(HandledStamp::class);
        $handledCount = count($handledStamps);

        if (1 !== $handledCount) {
            throw new LogicException(sprintf('Message "%s" must be handled exactly once, handled %d time(s).', $envelope->getMessage()::class, $handledCount));
        }

        return $handledStamps[0]->getResult();
    }
}
