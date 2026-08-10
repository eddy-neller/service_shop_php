<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// .env est ignore par Git : la suite doit rester executable sans lui,
// .env.test suffisant a fournir toutes les valeurs necessaires.
$root = dirname(__DIR__);
new Dotenv()->bootEnv(file_exists($root . '/.env') ? $root . '/.env' : $root . '/.env.test');
