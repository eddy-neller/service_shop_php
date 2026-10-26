<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Application\Catalog\Port\ProductImageUrlResolverInterface;
use App\Application\Shared\Port\FileInterface;
use App\Infrastructure\Symfony\Security\RoleSet;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Règle de dépendance de la Clean Architecture, vérifiée par PHPStan (PHPat) à chaque `make stan`,
 * donc à chaque commit (GrumPHP) et en CI.
 *
 * Source des règles : le `AGENTS.md` de chaque couche (`src/<Couche>/AGENTS.md`). Une règle modifiée
 * ici l'est aussi là-bas, et inversement. Les classes PHP natives (`DateTimeImmutable`, exceptions
 * SPL, `JsonSerializable`…) sont ignorées (`phpat.ignore_built_in_classes`) : elles sont permises
 * partout. Les classes des extensions (`MongoDB\…`, `Redis`) le sont aussi, à tort : le skill
 * `regle-dependance` les cherche à part.
 *
 * Ce n'est pas un test PHPUnit : PHPStan l'exécute, il est enregistré sous le tag `phpat.test`
 * dans `phpstan.dist.neon`. Il ne partage rien avec celui de `service_identity` : les contrats
 * admis diffèrent d'un service à l'autre.
 */
final class DependencyRule
{
    private const string DOMAIN = 'App\Domain';

    private const string APPLICATION = 'App\Application';

    private const string INFRASTRUCTURE = 'App\Infrastructure';

    private const string PRESENTATION = 'App\Presentation';

    private const string INTEGRATION_HANDLERS = 'App\Infrastructure\Symfony\Messenger\Event\Integration\Handler';

    /**
     * Le Domain ne connaît que lui-même : PHP natif, ses contextes et le SharedKernel.
     */
    public function testDomainDependsOnNothingButItself(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::DOMAIN))
            ->canOnly()->dependOn()
            ->classes(Selector::inNamespace(self::DOMAIN))
            ->because('Le Domain est pur : ni autre couche, ni framework, ni bibliothèque (src/Domain/AGENTS.md).');
    }

    /**
     * L'Application ne connaît que le Domain et ses propres contrats (Ports, CQRS, read models).
     * Toute dépendance technique passe par un Port implémenté en Infrastructure.
     */
    public function testApplicationDependsOnlyOnDomainAndItself(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::APPLICATION))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace(self::APPLICATION),
                Selector::inNamespace(self::DOMAIN),
            )
            ->because('Une dépendance externe devient un Port dans Application/…/Port, implémenté en Infrastructure (src/Application/AGENTS.md).');
    }

    /**
     * L'Infrastructure implémente des contrats : elle ne voit d'Application que ce qui en est un.
     * Jamais un use case, jamais un service applicatif. Les handlers d'intégration, adaptateurs
     * d'entrée du bus, ont leur propre règle juste en dessous.
     */
    public function testInfrastructureSeesOnlyApplicationContracts(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::INFRASTRUCTURE))
            ->excluding(Selector::inNamespace(self::INTEGRATION_HANDLERS))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::APPLICATION))
            ->excluding(
                Selector::inNamespace('/^App\\\Application\\\[A-Za-z]+\\\Port(\\\|$)/', true),
                Selector::inNamespace('App\Application\Shared\CQRS'),
                Selector::inNamespace('/^App\\\Application\\\[A-Za-z]+\\\ReadModel(\\\|$)/', true),
            )
            ->because('Infrastructure dépend des Ports, des contrats CQRS et des read models, jamais des use cases (src/Infrastructure/AGENTS.md).');
    }

    /**
     * Un handler d'intégration traduit un fait de `service_identity` en Command, qu'il dispatche :
     * il peut nommer la Command, jamais son handler.
     */
    public function testIntegrationHandlersDispatchCommandsOnly(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::INTEGRATION_HANDLERS))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::APPLICATION))
            ->excluding(
                Selector::inNamespace('/^App\\\Application\\\[A-Za-z]+\\\Port(\\\|$)/', true),
                Selector::inNamespace('App\Application\Shared\CQRS'),
                Selector::AllOf(
                    Selector::inNamespace('/^App\\\Application\\\[A-Za-z]+\\\UseCase\\\Command(\\\|$)/', true),
                    Selector::Not(Selector::classname('/Handler$/', true)),
                ),
            )
            ->because("Un handler d'intégration dispatche une Command sur le CommandBusInterface, il n'appelle pas son handler (src/Infrastructure/AGENTS.md).");
    }

    public function testInfrastructureIgnoresPresentation(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::INFRASTRUCTURE))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::PRESENTATION))
            ->because('La Presentation est un adaptateur d\'entrée : rien ne dépend d\'elle (src/Infrastructure/AGENTS.md).');
    }

    /**
     * La Presentation parle à l'Application par les bus : Commands, Queries, read models en retour.
     * Les seuls Ports admis sont les deux que documente src/Presentation/AGENTS.md.
     */
    public function testPresentationTalksToApplicationThroughBuses(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::PRESENTATION))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::APPLICATION))
            ->excluding(
                Selector::inNamespace('App\Application\Shared\CQRS'),
                // Les Commands et Queries, pas leurs handlers, qui vivent dans le même dossier.
                Selector::AllOf(
                    Selector::inNamespace('/^App\\\Application\\\[A-Za-z]+\\\UseCase(\\\|$)/', true),
                    Selector::Not(Selector::classname('/Handler$/', true)),
                ),
                Selector::inNamespace('/^App\\\Application\\\[A-Za-z]+\\\ReadModel(\\\|$)/', true),
                Selector::classname(FileInterface::class),
                Selector::classname(ProductImageUrlResolverInterface::class),
            )
            ->because('Presentation passe par CommandBusInterface / QueryBusInterface, jamais par un Port ni un handler (src/Presentation/AGENTS.md).');
    }

    /**
     * La Presentation n'injecte ni repository Doctrine, ni service d'Infrastructure. Seule exception :
     * les constantes de `RoleSet`, vocabulaire du claim `roles` du JWT, lues aussi par l'Infrastructure.
     */
    public function testPresentationIgnoresInfrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::PRESENTATION))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::INFRASTRUCTURE))
            ->excluding(Selector::classname(RoleSet::class))
            ->because('Presentation ne connaît pas l\'Infrastructure, hors constantes de RoleSet (src/Presentation/AGENTS.md).');
    }

    /**
     * La Presentation ne lit du Domain que des constantes de Value Objects (statuts) ; elle n'en
     * construit jamais. Les appels de factory (`CustomerStatus::fromString()`) échappent à PHPat :
     * le skill `regle-dependance` les cherche à part.
     */
    public function testPresentationNeverBuildsDomainObjects(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::PRESENTATION))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::DOMAIN))
            ->excluding(Selector::inNamespace('/^App\\\Domain\\\[A-Za-z]+\\\ValueObject(\\\|$)/', true))
            ->because("Presentation n'utilise du Domain que les constantes publiques des Value Objects (src/Presentation/AGENTS.md).");
    }
}
