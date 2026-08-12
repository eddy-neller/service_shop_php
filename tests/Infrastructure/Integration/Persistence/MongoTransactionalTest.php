<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence;

use App\Domain\Catalog\ValueObject\CategoryTitle;
use RuntimeException;
use Throwable;

/**
 * Garde-fou de l'atomicite des ecritures.
 *
 * `MongoTransactional::transactional()` ne tient sa promesse que si MongoDB tourne en
 * replica set : un serveur standalone refuse les transactions multi-documents. Retirer
 * `replication.replSetName` de `docker/mongodb/mongod.conf` ne casserait le demarrage de
 * rien — le flush deviendrait simplement une suite d'ecritures independantes, et
 * l'atomicite disparaitrait sans un seul message d'erreur.
 *
 * C'est ce test qui s'en apercoit. Ses deux cas de rollback ecrivent **d'abord** un
 * document valide, **puis** un document qui viole un index unique : sans transaction, le
 * premier survivrait au rejet du second. L'ordre n'est pas cosmetique, il fait le test —
 * verifie en remplacant `flush(['withTransaction' => true])` par `flush()`, qui les fait
 * bien passer au rouge tous les deux.
 *
 * Le rollback teste ici est celui du flush. Les requetes manuelles d'un repository
 * (`updateMany` de `shiftDescendantLevels`, agregations) n'en font pas partie : l'ODM ne
 * rend transactionnelles que les operations d'un meme flush. Les categories creees ici
 * sont donc volontairement sans parent, pour qu'aucune propagation de niveau ne vienne
 * brouiller ce qui est verifie.
 */
final class MongoTransactionalTest extends MongoPersistenceTestCase
{
    /**
     * Le cas nominal documente dans AGENTS.md : creer un produit et toucher sa categorie
     * dans un meme cas d'usage commite les deux agregats ensemble.
     */
    public function testTwoAggregatesOfTheSameUseCaseAreCommittedTogether(): void
    {
        $guitares = $this->aCategory('Guitares');

        $this->transactional->transactional(function () use ($guitares): void {
            $this->categories->save($guitares);
            $this->products->save($this->aProduct('Stratocaster', $guitares->getId()));
        });

        self::assertSame(1, $this->countIn('category', ['title' => 'Guitares']));
        self::assertSame(1, $this->countIn('product', ['title' => 'Stratocaster']));
    }

    /**
     * Le rollback dans une meme collection.
     *
     * `Basses` est valide et persistee en premier ; `Guitares` viole ensuite
     * `category_title_uniq`. Le flush echoue : `Basses` ne doit pas avoir survecu.
     */
    public function testAWriteRejectedByAUniqueIndexRollsBackTheWholeFlush(): void
    {
        $this->transactional->transactional(function (): void {
            $this->categories->save($this->aCategory('Guitares'));
        });

        $failed = $this->transactionalFailure(function (): void {
            $this->categories->save($this->aCategory('Basses'));
            $this->categories->save($this->aCategory('Guitares'));
        });

        self::assertInstanceOf(Throwable::class, $failed);
        self::assertSame(0, $this->countIn('category', ['title' => 'Basses']));
        self::assertSame(1, $this->countIn('category', ['title' => 'Guitares']));
    }

    /**
     * Le rollback a travers deux collections.
     *
     * MongoDB ecrit `category` et `product` en deux operations distinctes : sans
     * transaction, l'echec de la seconde laisserait la premiere en base.
     */
    public function testARollbackSpansSeveralCollections(): void
    {
        $basses = $this->aCategory('Basses');

        $this->transactional->transactional(function () use ($basses): void {
            $this->categories->save($basses);
            $this->products->save($this->aProduct('Jazz Bass', $basses->getId()));
        });

        $guitares = $this->aCategory('Guitares');

        $failed = $this->transactionalFailure(function () use ($guitares): void {
            $this->categories->save($guitares);
            $this->products->save($this->aProduct('Jazz Bass', $guitares->getId()));
        });

        self::assertInstanceOf(Throwable::class, $failed);
        self::assertSame(0, $this->countIn('category', ['title' => 'Guitares']));
        self::assertSame(1, $this->countIn('product', ['title' => 'Jazz Bass']));
    }

    /**
     * Une exception levee par le callback survient avant le flush : rien n'est ecrit, et
     * l'exception doit ressortir telle quelle plutot que d'etre avalee.
     */
    public function testAnExceptionRaisedByTheCallbackWritesNothingAndPropagates(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('regle metier violee');

        try {
            $this->transactional->transactional(function (): void {
                $this->categories->save($this->aCategory('Guitares'));

                throw new RuntimeException('regle metier violee');
            });
        } finally {
            self::assertSame(0, $this->countIn('category', ['title' => 'Guitares']));
        }
    }

    /**
     * L'echec ne doit pas laisser le document rejete dans l'identity map : le `clear()`
     * de `MongoTransactional` garantit qu'une lecture suivante repart de la base et ne
     * voit pas le fantome.
     */
    public function testAFailedTransactionLeavesNoGhostInTheIdentityMap(): void
    {
        $this->transactionalFailure(function (): void {
            $this->categories->save($this->aCategory('Guitares'));

            throw new RuntimeException('regle metier violee');
        });

        self::assertNull($this->categories->findByTitle(CategoryTitle::fromString('Guitares')));
    }

    public function testTheCallbackResultIsReturned(): void
    {
        $guitares = $this->aCategory('Guitares');

        $id = $this->transactional->transactional(function () use ($guitares): string {
            $this->categories->save($guitares);

            return $guitares->getId()->toString();
        });

        self::assertSame($guitares->getId()->toString(), $id);
    }

    /**
     * Joue une operation censee echouer et rend l'exception, pour que l'appelant puisse
     * verifier l'etat de la base ensuite. Un `expectException()` couperait le test avant
     * ces assertions, qui sont precisement l'objet du garde-fou.
     */
    private function transactionalFailure(callable $operation): ?Throwable
    {
        try {
            $this->transactional->transactional($operation);
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * Compte en interrogeant la collection brute.
     *
     * Passer par le `DocumentManager` ferait lire l'identity map : un document rejete par
     * le serveur pourrait y repondre present et rendre le test complaisant.
     *
     * @param array<string, mixed> $filter
     */
    private function countIn(string $collection, array $filter): int
    {
        return $this->database()->selectCollection($collection)->countDocuments($filter);
    }
}
