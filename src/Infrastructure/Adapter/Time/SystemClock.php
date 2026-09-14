<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Time;

use App\Application\Shared\Port\ClockInterface;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface as SymfonyClockInterface;

final readonly class SystemClock implements ClockInterface
{
    public function __construct(private SymfonyClockInterface $clock)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
