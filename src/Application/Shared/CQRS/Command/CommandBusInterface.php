<?php

declare(strict_types=1);

namespace App\Application\Shared\CQRS\Command;

interface CommandBusInterface
{
    public function dispatch(CommandInterface $command): mixed;
}
