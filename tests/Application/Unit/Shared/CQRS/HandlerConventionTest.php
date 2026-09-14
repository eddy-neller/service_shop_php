<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Shared\CQRS;

use App\Application\Shared\CQRS\Command\CommandHandlerInterface;
use App\Application\Shared\CQRS\Query\QueryHandlerInterface;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionNamedType;
use RegexIterator;

/**
 * Garde-fou de la convention CQRS. Il tient trois promesses que rien d'autre ne verifie :
 *
 * 1. tout `*Command` / `*Query` a son handler, nomme par convention ;
 * 2. ce handler implemente le bon contrat et son `handle()` type exactement le message —
 *    c'est ce type qui sert de cle de routage a Messenger, donc une derive silencieuse
 *    ici produit un « pas de handler » a l'execution, jamais a la compilation ;
 * 3. tout cas d'usage a son test unitaire a ports mockes.
 */
final class HandlerConventionTest extends TestCase
{
    public function testAllCommandsHaveHandlers(): void
    {
        foreach ($this->useCaseClasses('Command') as $commandClass) {
            $handlerClass = preg_replace('/Command$/', 'CommandHandler', $commandClass);

            $this->assertTrue(
                class_exists((string) $handlerClass),
                sprintf('Missing handler for command: %s (expected %s)', $commandClass, (string) $handlerClass),
            );

            $this->assertHandlerContract((string) $handlerClass, $commandClass, CommandHandlerInterface::class);
        }
    }

    public function testAllQueriesHaveHandlers(): void
    {
        foreach ($this->useCaseClasses('Query') as $queryClass) {
            $handlerClass = preg_replace('/Query$/', 'QueryHandler', $queryClass);

            $this->assertTrue(
                class_exists((string) $handlerClass),
                sprintf('Missing handler for query: %s (expected %s)', $queryClass, (string) $handlerClass),
            );

            $this->assertHandlerContract((string) $handlerClass, $queryClass, QueryHandlerInterface::class);
        }
    }

    public function testEveryCommandHasATest(): void
    {
        $this->assertUseCasesAreTested('Command');
    }

    public function testEveryQueryHasATest(): void
    {
        $this->assertUseCasesAreTested('Query');
    }

    private function assertUseCasesAreTested(string $suffix): void
    {
        $missing = [];

        foreach ($this->useCaseClasses($suffix) as $useCaseClass) {
            $testClass = $this->expectedTestClass($useCaseClass, $suffix);

            if (!class_exists($testClass)) {
                $missing[] = sprintf('%s (expected %s)', $useCaseClass, $testClass);
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            sprintf("Missing application %s test(s):\n  - %s", $suffix, implode("\n  - ", $missing)),
        );
    }

    /**
     * @return array<int, class-string>
     */
    private function useCaseClasses(string $suffix): array
    {
        $baseDir = dirname(__DIR__, 5) . '/src/Application';
        $classes = [];

        foreach ($this->findFiles($baseDir, '/' . $suffix . '\\.php$/') as $file) {
            $class = $this->classFromFile($file, $baseDir, 'App\\Application\\');

            $this->assertTrue(class_exists($class), sprintf('Class not found for file: %s', $file));

            /* @var class-string $class */
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Associe un cas d'usage a son test unitaire attendu :
     * App\Application\Catalog\UseCase\Command\CreateProductByAdmin\CreateProductByAdminCommand
     * -> App\Tests\Application\Unit\Catalog\UseCase\Command\CreateProductByAdminTest.
     */
    private function expectedTestClass(string $useCaseClass, string $suffix): string
    {
        $parts = explode('\\', $useCaseClass);
        $shortName = array_pop($parts);
        array_pop($parts); // retire le dossier du cas d'usage (ex. CreateProductByAdmin)

        $testName = (string) preg_replace('/' . preg_quote($suffix, '/') . '$/', 'Test', $shortName);
        $testNamespace = str_replace('App\\Application\\', 'App\\Tests\\Application\\Unit\\', implode('\\', $parts));

        return $testNamespace . '\\' . $testName;
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

    /**
     * @param class-string $handlerClass
     * @param class-string $messageClass
     * @param class-string $handlerInterface
     */
    private function assertHandlerContract(string $handlerClass, string $messageClass, string $handlerInterface): void
    {
        $this->assertTrue(
            is_subclass_of($handlerClass, $handlerInterface),
            sprintf('Handler %s must implement %s.', $handlerClass, $handlerInterface),
        );
        $this->assertTrue(
            method_exists($handlerClass, 'handle'),
            sprintf('Handler %s must define handle().', $handlerClass),
        );

        $parameters = (new ReflectionMethod($handlerClass, 'handle'))->getParameters();
        $this->assertCount(1, $parameters, sprintf('Handler %s::handle() must accept exactly one message.', $handlerClass));

        $messageType = $parameters[0]->getType();
        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $messageType,
            sprintf('Handler %s::handle() must type its message.', $handlerClass),
        );
        $this->assertSame(
            $messageClass,
            $messageType->getName(),
            sprintf('Handler %s::handle() must accept %s.', $handlerClass, $messageClass),
        );
    }
}
