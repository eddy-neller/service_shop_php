<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo;

use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\SharedKernel\Exception\ConcurrentModificationException;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\LockException;
use Throwable;

final readonly class MongoTransactional implements TransactionalInterface
{
    public function __construct(
        private DocumentManager $documentManager,
    ) {
    }

    public function transactional(callable $operation)
    {
        try {
            $result = $operation();

            $this->documentManager->flush(['withTransaction' => true]);

            return $result;
        } catch (Throwable $exception) {
            $this->documentManager->clear();

            throw $this->translate($exception);
        }
    }

    /**
     * Traduit l'echec du verrou optimiste en exception de domaine.
     *
     * Les documents dont l'etat depend de leur propre contenu — le plafond d'adresses d'un
     * client, la fusion de lignes d'un panier — portent un `#[MongoDB\Version]`, faute de
     * `SELECT … FOR UPDATE` cote MongoDB. Quand le perdant d'une course est rejete, l'ODM
     * leve une `LockException` : sans cette traduction elle remonterait telle quelle et
     * ressortirait en **500**, alors que c'est un conflit ordinaire que le client doit
     * rejouer — donc un **409**.
     *
     * La traduction vit ici, et pas dans `api_platform.yaml`, pour que le nom Doctrine reste
     * a l'interieur de `Persistence/`.
     */
    private function translate(Throwable $exception): Throwable
    {
        return $exception instanceof LockException
            ? new ConcurrentModificationException(previous: $exception)
            : $exception;
    }
}
