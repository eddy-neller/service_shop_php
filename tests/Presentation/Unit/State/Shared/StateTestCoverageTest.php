<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Shared;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Garde-fou : chaque State API Platform (Provider/Processor) de la couche Presentation
 * doit avoir son test unitaire. Le build casse des qu'un Provider/Processor est livre sans test.
 *
 * L'integration Doctrine d'API Platform etant coupee dans ce service, **tous** les providers
 * et processors sont ecrits a la main : ce garde-fou couvre donc l'integralite du chemin de
 * lecture et d'ecriture HTTP, pas un residu.
 *
 * L'arborescence des tests reflete celle de `src/`, en remontant le segment `State` :
 *   src   : src/Presentation/<Contexte>/State/<reste...>/<Nom>.php
 *   test  : tests/Presentation/Unit/State/<Contexte>/<reste...>/<Nom>Test.php
 */
final class StateTestCoverageTest extends TestCase
{
    public function testEveryStateHasATest(): void
    {
        $srcDir = dirname(__DIR__, 5) . '/src/Presentation';
        $stateTestsDir = dirname(__DIR__, 2) . '/State';

        $missing = [];

        foreach ($this->findFiles($srcDir, '#/State/.*(Provider|Processor)\.php$#') as $file) {
            $fqcn = $this->classFromFile($file, $srcDir, 'App\\Presentation\\');
            $expectedTestFile = $this->expectedTestFile($file, $srcDir, $stateTestsDir);

            if (!is_file($expectedTestFile)) {
                $missing[] = sprintf('%s (expected %s)', $fqcn, $expectedTestFile);
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            sprintf("Missing presentation State test(s):\n  - %s", implode("\n  - ", $missing)),
        );
    }

    /**
     * Reflete un fichier State sur son test, en remontant le segment `State` a la racine :
     * src  src/Presentation/Catalog/State/Product/ProductGetProvider.php
     * test tests/Presentation/Unit/State/Catalog/Product/ProductGetProviderTest.php.
     */
    private function expectedTestFile(string $file, string $srcDir, string $stateTestsDir): string
    {
        $relative = substr($file, strlen($srcDir) + 1, -strlen('.php'));
        $segments = explode('/', $relative);

        // Retire le premier segment `State` ; le reste du chemin se reflete sous tests/Unit/State.
        $stateIndex = array_search('State', $segments, true);
        if (false !== $stateIndex) {
            unset($segments[$stateIndex]);
        }

        $segments[array_key_last($segments)] .= 'Test';

        return $stateTestsDir . '/' . implode('/', $segments) . '.php';
    }

    /**
     * @return array<int, string>
     */
    private function findFiles(string $baseDir, string $pattern): array
    {
        if (!is_dir($baseDir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir)
        );

        $files = new RegexIterator($iterator, $pattern);
        $results = [];

        foreach ($files as $file) {
            $results[] = $file->getPathname();
        }

        return $results;
    }

    private function classFromFile(string $file, string $baseDir, string $baseNamespace): string
    {
        $relative = substr($file, strlen($baseDir) + 1);

        return $baseNamespace . str_replace(['/', '.php'], ['\\', ''], $relative);
    }
}
