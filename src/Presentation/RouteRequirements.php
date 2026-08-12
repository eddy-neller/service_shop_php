<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * @codeCoverageIgnore
 */
class RouteRequirements
{
    private function __construct()
    {
    }

    // UUID strict RFC 4122 v1–v5 (version + variant contrôlés)
    public const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';
}
