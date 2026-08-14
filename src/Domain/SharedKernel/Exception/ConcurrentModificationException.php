<?php

declare(strict_types=1);

namespace App\Domain\SharedKernel\Exception;

use Throwable;

/**
 * Deux ecritures concurrentes ont vise le meme agregat et la seconde a perdu.
 *
 * Elle existe parce que MongoDB n'a pas de `SELECT … FOR UPDATE` : la ou PostgreSQL
 * serialisait les ecrivains par un verrou pessimiste, les agregats dont l'etat depend de
 * leur propre contenu — le plafond d'adresses d'un client, la fusion de lignes d'un panier —
 * sont proteges par un **verrou optimiste** pose en persistance. Le perdant est rejete, il
 * n'ecrase pas.
 *
 * Le domaine ne la leve jamais lui-meme : elle est traduite depuis l'infrastructure par
 * `MongoTransactional`, pour que le nom Doctrine ne franchisse pas la frontiere de couche.
 * Cote HTTP elle vaut **409**, comme tout `ConflictInterface` : rejouer la requete est la
 * bonne reaction, et elle a de bonnes chances d'aboutir.
 */
final class ConcurrentModificationException extends DomainException implements ConflictInterface
{
    public function __construct(
        string $message = 'The resource was modified concurrently, please retry.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
