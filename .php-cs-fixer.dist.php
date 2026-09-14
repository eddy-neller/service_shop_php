<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude([
        'config',
        'var',
        'vendor',
    ])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
        'public/index.php',
        'tests/bootstrap.php',
        'rector.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'concat_space' => ['spacing' => 'one'],
        'global_namespace_import' => false,
    ])
    ->setFinder($finder);
