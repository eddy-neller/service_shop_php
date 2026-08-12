<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo;

use App\Application\Shared\Port\TransactionalInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
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

            throw $exception;
        }
    }
}
