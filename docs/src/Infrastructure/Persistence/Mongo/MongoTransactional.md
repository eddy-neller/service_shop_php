# `MongoTransactional`

Source : [`src/Infrastructure/Persistence/Mongo/MongoTransactional.php`](../../../../../src/Infrastructure/Persistence/Mongo/MongoTransactional.php).

`MongoTransactional` est l'adaptateur MongoDB du port applicatif `TransactionalInterface`. Il delimite l'unite atomique d'une commande : le callback du handler accumule les modifications dans le `DocumentManager`, puis la classe les ecrit par un unique `flush()` execute dans une transaction MongoDB.

## Contrat

```text
handler Application
    |
    `-- TransactionalInterface::transactional(callback)
            |
            +-- callback : repositories persist()/remove()
            |
            +-- DocumentManager::flush(withTransaction: true)
            |       |
            |       `-- commit de tous les documents, ou rollback complet
            |
            `-- en cas d'erreur : DocumentManager::clear(), puis rethrow
```

Le resultat du callback est retourne sans transformation. Toute exception provenant du callback ou du `flush()` est propagee a l'appelant. Avant cette propagation, `clear()` detache les documents encore suivis par l'ODM : un `persist()` effectue avant l'echec ne peut pas etre emporte par le flush d'une requete ulterieure.

## Invariant de flush unique

Avec Doctrine ODM, la transaction multi-documents couvre les operations d'un meme `flush()`. Les repositories `MongoCategoryRepository` et `MongoProductRepository` ne font donc que `persist()` ou `remove()` ; ils ne doivent jamais appeler `flush()`.

Cette repartition permet, par exemple, de creer un produit et d'incrementer le compteur denormalise de sa categorie dans le meme cas d'usage : les deux documents sont valides ensemble, ou aucun ne l'est. Ajouter un flush dans un repository couperait cette unite de travail et pourrait rendre un etat partiel visible sans faire echouer le demarrage.

Le callback doit rester court et se limiter aux operations locales au catalogue. Les lectures de type `findById()` ou `findByTitle()` ne constituent pas une protection contre une ecriture concurrente : les index uniques MongoDB restent l'autorite pour les contraintes d'unicite.

## Prerequis MongoDB

Les transactions multi-documents exigent un replica set, y compris sur l'environnement mono-noeud de developpement et de test. La configuration versionnee [`docker/mongodb/mongod.conf`](../../../../../docker/mongodb/mongod.conf) declare `rs0`, et le healthcheck initialise le replica set avant que `app` ne demarre.

Un serveur MongoDB standalone ne rendrait pas le service indisponible au demarrage, mais supprimerait l'atomicite de cette classe. Il faut donc conserver `replication.replSetName` dans la configuration MongoDB, ne pas le deplacer dans une commande Docker ephemere, et ne pas retirer l'initialisation `rs.initiate()` du healthcheck.

## Liaison et verification

`config/services.yaml` associe `TransactionalInterface` a `MongoTransactional`. Les handlers de commande ne connaissent ainsi ni `DocumentManager` ni Doctrine ODM.

[`MongoTransactionalTest`](../../../../../tests/Infrastructure/Integration/Persistence/MongoTransactionalTest.php) (suite `infra.persist`) garantit :

- les deux agregats d'un meme cas d'usage commitent ensemble ;
- un echec tardif du flush, apres qu'une premiere ecriture a ete soumise, annule aussi cette ecriture — dans une meme collection comme a travers deux collections ;
- une exception dans le callback ne persiste aucun document, ressort telle quelle, et ne laisse pas le document rejete dans l'identity map ;
- le resultat du callback est bien rendu a l'appelant.

Les deux cas de rollback sont le garde-fou du replica set : sans transaction reelle, l'insertion partielle survit a l'echec de l'index unique. C'est verifie, pas suppose — remplacer `flush(['withTransaction' => true])` par `flush()` les fait passer au rouge tous les deux.

## Points de vigilance lors d'une modification

- Ne pas ajouter de `flush()` dans un repository ou dans un handler ; ce composant est le seul proprietaire du flush transactionnel.
- Conserver `withTransaction: true` : retirer cette option transforme silencieusement une commande multi-documents en ecritures independantes.
- Conserver `clear()` sur toute exception, y compris celle declenchee pendant le flush.
- Ne pas etendre la transaction a des appels reseau, a un broker ou a des effets externes. Les futurs Domain Events devront etre poses dans la meme unite de travail MongoDB via une outbox, puis traites hors transaction.
