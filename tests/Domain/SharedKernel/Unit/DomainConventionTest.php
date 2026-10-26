<?php

declare(strict_types=1);

namespace App\Tests\Domain\SharedKernel\Unit;

use App\Domain\SharedKernel\Event\DomainEventIdentityTrait;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Domain\SharedKernel\Exception\DomainException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RegexIterator;

/**
 * Garde-fou : les règles tactiques du Domain (`src/Domain/AGENTS.md`) qui se vérifient sans
 * jugement — forme des Value Objects, des agrégats, des Domain Events et des exceptions, et
 * l'horloge.
 *
 * Ne teste aucun comportement : il balaye `src/` par réflexion et casse le build dès qu'une classe
 * s'écarte de la forme attendue. Ce qui demande un jugement (un concept resté en `string`, une
 * décision métier prise dans un handler, un secret dans un événement) relève du skill
 * `ddd-tactique` de `back_php/.claude/skills/`.
 *
 * **Pas de liste d'exclusion**, pour la même raison que `DomainTestCoverageTest` : une trappe
 * pré-percée finit toujours par servir.
 */
final class DomainConventionTest extends TestCase
{
    /**
     * `final`, constructeur `private` : la création passe par une factory nommée, seule à pouvoir
     * valider l'invariant.
     */
    public function testValueObjectsAreBuiltOnlyByTheirFactories(): void
    {
        $this->assertNoViolation('Value Object', 'ValueObject', static function (ReflectionClass $class): array {
            $violations = [];

            if (!$class->isFinal()) {
                $violations[] = 'not final';
            }

            $constructor = $class->getConstructor();

            if (null === $constructor || !$constructor->isPrivate()) {
                $violations[] = 'constructor must be private';
            }

            return $violations;
        });
    }

    public function testValueObjectsAreImmutable(): void
    {
        $this->assertNoViolation('Value Object', 'ValueObject', fn (ReflectionClass $class): array => $this->mutableProperties($class, true));
    }

    public function testValueObjectsCompareByValue(): void
    {
        $this->assertNoViolation('Value Object', 'ValueObject', static function (ReflectionClass $class): array {
            if (!$class->hasMethod('equals')) {
                return ['no equals(self $other): bool'];
            }

            $equals = $class->getMethod('equals');
            $parameter = $equals->getParameters()[0] ?? null;

            if (!$equals->isPublic() || $equals->isStatic() || 'self' !== (string) $parameter?->getType() || 'bool' !== (string) $equals->getReturnType()) {
                return ['equals() must be public function equals(self $other): bool'];
            }

            return [];
        });
    }

    /**
     * Un agrégat se crée par factory (`create`, `register`, `reconstitute`…) et ne change que par
     * ses méthodes métier : pas de constructeur public, pas de `setXxx()` public.
     */
    public function testEntitiesChangeOnlyThroughBusinessMethods(): void
    {
        $this->assertNoViolation('entity', 'Model', static function (ReflectionClass $class): array {
            $violations = [];

            if (!$class->isFinal()) {
                $violations[] = 'not final';
            }

            if ($class->getConstructor()?->isPublic() ?? false) {
                $violations[] = 'public constructor';
            }

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (1 === preg_match('/^set[A-Z]/', $method->getName())) {
                    $violations[] = sprintf('public %s()', $method->getName());
                }
            }

            return $violations;
        });
    }

    /**
     * `updatedAt` ne change que par `touch($now)` : une affectation directe est une méthode métier
     * qui contourne la seule règle de mise à jour du timestamp. Le constructeur, qui l'initialise,
     * est le seul autre endroit permis.
     */
    public function testEntitiesTouchUpdatedAtOnlyThroughTouch(): void
    {
        $this->assertNoViolation('entity', 'Model', static function (ReflectionClass $class): array {
            $file = (string) $class->getFileName();
            $allowed = array_filter([
                $class->hasMethod('touch') ? $class->getMethod('touch') : null,
                $class->getConstructor(),
            ]);
            $violations = [];

            foreach (file($file) ?: [] as $index => $line) {
                $number = $index + 1;

                if (1 !== preg_match('/\$this->updatedAt\s*=[^=]/', $line)) {
                    continue;
                }

                foreach ($allowed as $method) {
                    if ($number >= $method->getStartLine() && $number <= $method->getEndLine()) {
                        continue 2;
                    }
                }

                $violations[] = sprintf('updatedAt assigned outside touch() at line %d', $number);
            }

            return $violations;
        });
    }

    /**
     * Un événement est un fait accompli, immuable, porteur de l'`eventId` qui sert de clé de
     * déduplication au consommateur.
     */
    public function testDomainEventsAreImmutableFacts(): void
    {
        $this->assertNoViolation('Domain Event', 'Event', function (ReflectionClass $class): array {
            $violations = $this->mutableProperties($class, false);

            if (!$class->isFinal()) {
                $violations[] = 'not final';
            }

            if (!$class->implementsInterface(DomainEventInterface::class)) {
                $violations[] = 'does not implement DomainEventInterface';
            }

            if (!in_array(DomainEventIdentityTrait::class, $class->getTraitNames(), true)) {
                $violations[] = 'does not use DomainEventIdentityTrait';
            }

            if (!str_ends_with($class->getShortName(), 'Event')) {
                $violations[] = 'name must end with Event';
            }

            return $violations;
        });
    }

    /**
     * Toute exception du Domain hérite de la base de son contexte, donc de `DomainException` :
     * c'est ce qui la fait retomber au pire sur le 400 du fallback, jamais sur un 500.
     */
    public function testDomainExceptionsBelongToTheDomainHierarchy(): void
    {
        $this->assertNoViolation('exception', 'Exception', static function (ReflectionClass $class): array {
            if (DomainException::class === $class->getName() || $class->isSubclassOf(DomainException::class)) {
                return [];
            }

            return ['does not extend ' . DomainException::class];
        });
    }

    /**
     * Seule l'Infrastructure lit l'horloge réelle : Domain et Application reçoivent `$now`, ou
     * injectent `ClockInterface`.
     */
    public function testOnlyInfrastructureReadsTheClock(): void
    {
        $violations = [];

        foreach (['Domain', 'Application'] as $layer) {
            foreach ($this->phpFiles($this->projectDir() . '/src/' . $layer) as $file) {
                foreach (file($file) ?: [] as $index => $line) {
                    if (1 === preg_match('/new\s+\\\?DateTime(Immutable)?\(\s*(\'now\'|"now")?\s*\)/', $line)) {
                        $violations[] = sprintf('%s:%d', $this->relative($file), $index + 1);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            sprintf("The real clock is read outside Infrastructure:\n  - %s", implode("\n  - ", $violations)),
        );
    }

    /**
     * @param callable(ReflectionClass<object>): list<string> $check
     */
    private function assertNoViolation(string $kind, string $category, callable $check): void
    {
        $violations = [];

        foreach ($this->domainClasses($category) as $class) {
            foreach ($check($class) as $violation) {
                $violations[] = sprintf('%s: %s', $class->getName(), $violation);
            }
        }

        $this->assertSame(
            [],
            $violations,
            sprintf("%s convention violated (src/Domain/AGENTS.md):\n  - %s", $kind, implode("\n  - ", $violations)),
        );
    }

    /**
     * Les propriétés déclarées par la classe ou ses traits, hors statiques, qui ne sont pas
     * `readonly` — et, si demandé, qui ne sont pas `private`.
     *
     * @param ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private function mutableProperties(ReflectionClass $class, bool $mustBePrivate): array
    {
        $violations = [];

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            if (!$property->isReadOnly()) {
                $violations[] = sprintf('property $%s is not readonly', $property->getName());
            }

            if ($mustBePrivate && !$property->isPrivate()) {
                $violations[] = sprintf('property $%s is not private', $property->getName());
            }
        }

        return $violations;
    }

    /**
     * Les classes concrètes rangées sous un dossier `<category>/` d'un contexte du Domain.
     * Interfaces, traits, enums et classes abstraites n'ont pas de forme à respecter ici.
     *
     * @return list<ReflectionClass<object>>
     */
    private function domainClasses(string $category): array
    {
        $domainDir = $this->projectDir() . '/src/Domain';
        $classes = [];

        foreach ($this->phpFiles($domainDir) as $file) {
            $relative = substr($file, strlen($domainDir) + 1, -strlen('.php'));

            if (!in_array($category, explode('/', $relative), true)) {
                continue;
            }

            $fqcn = 'App\\Domain\\' . str_replace('/', '\\', $relative);

            if (!class_exists($fqcn)) {
                continue;
            }

            $class = new ReflectionClass($fqcn);

            if (!$class->isAbstract() && !$class->isEnum()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $baseDir): array
    {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir)),
            '/\.php$/',
        );
        $results = [];

        foreach ($files as $file) {
            $results[] = $file->getPathname();
        }

        sort($results);

        return $results;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen($this->projectDir()) + 1);
    }

    private function projectDir(): string
    {
        return dirname(__DIR__, 4);
    }
}
