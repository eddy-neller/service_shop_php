<?php

declare(strict_types=1);

namespace App\Application\Shared\CQRS\Query;

interface QueryBusInterface
{
    public function dispatch(QueryInterface $query): mixed;
}
