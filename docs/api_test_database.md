# Base MongoDB des tests API

Les tests API ne chargent **pas** de fixtures `test`. Chaque `setUp()` de
`tests/Presentation/Api/BaseTest.php` vide les collections avec `deleteMany()`, puis appelle le
contrat `seedTestData()` de la classe de test. Les seeders `Catalog/` et `Customer/` reconstruisent
le jeu minimal par les repositories et `MongoTransactional` : six catégories, six produits et les
clients nécessaires aux routes `/me`.

`BaseTest` reste ainsi responsable de l'infrastructure commune (noyau, client HTTP, JWT, reset),
tandis que les données restent proches des tests qui les utilisent. `CartTest` compose les seeders
Catalog et Customer ; les tests Catalog ou Customer ne chargent que leur domaine.

Ce choix préserve l'isolation de chaque scénario sans dépendre d'un état préchargé dans
`service_shop_test`. Les index ODM sont conservés et posés une seule fois par processus ; il ne faut
jamais remplacer le `deleteMany()` par un `drop()`.

## Pourquoi pas un rollback à la DAMA ?

`dama/doctrine-test-bundle` s'appuie sur une connexion DBAL unique, maintenue ouverte pour le test.
Les transactions des use cases deviennent alors des savepoints : leur `commit()` ne valide pas la
transaction racine, annulée par PHPUnit.

Ce service utilise Doctrine MongoDB ODM. `MongoTransactional` lance son flush avec
`withTransaction`, ce qui ouvre et valide une session MongoDB propre au cas d'usage. MongoDB n'a pas
de transaction imbriquée ni de session ambiante automatiquement partagée par les repositories. Une
transaction ouverte dans `BaseTest` n'engloberait donc ni les flushes ni toutes les lectures.

Rendre ce rollback possible demanderait de faire circuler explicitement une session de test dans
`src/Infrastructure/Persistence/Mongo/` et dans tous les repositories. Ce couplage de production
uniquement destiné aux tests ne justifie pas le gain face au seed réduit, rapide et déterministe.
