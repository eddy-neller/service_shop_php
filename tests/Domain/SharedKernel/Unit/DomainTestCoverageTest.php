<?php

declare(strict_types=1);

namespace App\Tests\Domain\SharedKernel\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Garde-fou : chaque Entite/Agregat (`Model/`) et Value Object (`ValueObject/`)
 * de la couche Domain doit avoir son test unitaire.
 *
 * Le build casse des qu'un Model ou un VO est livre sans test, quel que soit le contexte.
 *
 * Ce service a **aplati** les sous-contextes : le catalogue vit
 * en `src/Domain/Catalog/`, pas en `src/Domain/Shop/Catalog/`. Le mapping reste neanmoins
 * tolerant a un sous-contexte, pour le jour ou l'un d'eux en gagnerait un :
 *   src   : src/Domain/<Ctx>/<SousContexte?>/<Categorie>/<Nom>.php
 *   test  : tests/Domain/<Ctx>/Unit/<Categorie>/<SousContexte?>/<Nom>Test.php
 */
final class DomainTestCoverageTest extends TestCase
{
    public function testEveryEntityHasATest(): void
    {
        $this->assertModelIsFullyTested('Model');
    }

    public function testEveryValueObjectHasATest(): void
    {
        $this->assertModelIsFullyTested('ValueObject');
    }

    private function assertModelIsFullyTested(string $category): void
    {
        $missing = [];

        foreach ($this->domainContexts() as $context => $paths) {
            $sourceFiles = $this->findFiles($paths['src'], '#/' . $category . '/[^/]+\.php$#');

            foreach ($sourceFiles as $file) {
                $fqcn = $this->classFromFile($file, $paths['src'], 'App\\Domain\\' . $context . '\\');
                $expectedTestFile = $this->expectedTestFile($file, $paths['src'], $paths['tests']);

                if (!is_file($expectedTestFile)) {
                    $missing[] = sprintf('%s (expected %s)', $fqcn, $expectedTestFile);
                }
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            sprintf("Missing domain %s test(s):\n  - %s", $category, implode("\n  - ", $missing)),
        );
    }

    /**
     * Decouvre chaque contexte sous src/Domain/.
     *
     * @return array<string, array{src: string, tests: string}>
     */
    private function domainContexts(): array
    {
        $projectDir = dirname(__DIR__, 4);
        $domainDir = $projectDir . '/src/Domain';
        $testsDir = $projectDir . '/tests/Domain';
        $contexts = [];

        foreach (scandir($domainDir) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $src = $domainDir . '/' . $entry;

            if (is_dir($src)) {
                $contexts[$entry] = [
                    'src' => $src,
                    'tests' => $testsDir . '/' . $entry . '/Unit',
                ];
            }
        }

        return $contexts;
    }

    /**
     * Associe un fichier source a son test attendu, en permutant categorie et sous-contexte :
     * src/Domain/Catalog/ValueObject/ProductId.php
     * -> tests/Domain/Catalog/Unit/ValueObject/ProductIdTest.php.
     */
    private function expectedTestFile(string $file, string $srcDir, string $testsDir): string
    {
        $relative = substr($file, strlen($srcDir) + 1, -strlen('.php'));
        $segments = explode('/', $relative);
        $name = array_pop($segments);

        $category = null;
        $subContext = [];

        foreach ($segments as $segment) {
            if (null === $category && in_array($segment, ['Model', 'ValueObject'], true)) {
                $category = $segment;

                continue;
            }

            $subContext[] = $segment;
        }

        $parts = array_filter([$category, ...$subContext, $name . 'Test']);

        return $testsDir . '/' . implode('/', $parts) . '.php';
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
