# AGENTS.md — service_shop

> Guide pour humains **et** agents. Service du bounded context Shop.
> Jalon 1 : authentification. Jalon 2, etape A : `Catalog` sur MongoDB.
> **Jalon 2, etape B : Domain Events, outbox MongoDB, worker, cache de queries.**

---

## Perimetre : pile PHP uniquement

`back_php/` et `back_js/` sont **deux implementations paralleles et independantes du meme systeme**,
construites pour la montee en competence. Elles ne communiquent **jamais** entre elles.

Ce service appartient a la pile PHP. Son emetteur de tokens est `service_identity/`. `back_js/service_identity`
n'intervient a aucun moment — ne pas raisonner sur les deux piles a la fois.

---

## Ce que ce service est, et n'est pas

- **Est** : un consommateur de JWT. Il valide signature + `exp` et reconstruit l'identite depuis les
  claims. Il possede desormais **ses propres donnees** : le catalogue, dans **sa** base MongoDB.
- **N'est pas** : un emetteur. Il ne detient que la cle **publique**. Il est structurellement
  incapable de forger un token, et cette propriete doit etre preservee.
- **N'a pas** : de broker ni le moindre acces a la base de `service_identity`. Aucune de ces dependances ne
  doit etre ajoutee sans une raison metier explicite. L'outbox des Domain Events est une collection
  de **sa** base ; le cache de queries vit dans un Redis **dedie** a ce service, partage entre ses
  replicas et jamais mutualise avec `service_identity`.

Le service demarre et repond **seul**, sans la stack de `service_identity`. C'est une propriete verifiee par
`GET /health`, a preserver. Sa base lui appartient : `docker-compose.yaml` ne pointe vers aucune
stack externe.

---

## Decision d'architecture : fenetre de revocation de 900 s

**Un compte revoque conserve l'acces aux endpoints de ce service pendant au maximum `JWT_TTL`
(900 s). C'est un choix delibere, pas un trou de securite.**

Le token emis par `service_identity/` porte un claim `auth_version` : ce service **l'ignore volontairement**.
Le verifier imposerait un etat partage entre services (Redis commun) ou une invalidation de cache
distribuee — deux couplages juges plus couteux que la fenetre.

La revocation qui fait autorite reste dans le service emetteur : `POST /api/auth/token/refresh` +
suppression des refresh tokens (`RevokeSessionsHandler` cote `api/`). Un compte revoque ne peut pas
obtenir de **nouveau** token ; seul l'access token deja emis survit jusqu'a son `exp`.

Avant de proposer une revocation immediate ici, relire ce paragraphe.

---

## Regles sur les cles

- `config/jwt/public.pem` — cle **publique** de l'emetteur, copiee depuis `api/config/jwt/public.pem`
  (`make jwt-public-key`). Non versionnee.
- **Ne jamais copier `private.pem` ni `JWT_PASSPHRASE` dans ce depot.** Un service qui valide ne doit
  pas pouvoir emettre.
- `config/jwt/test/` — paire dediee aux tests, **versionnee volontairement** : elle ne protege rien
  et rend la suite reproductible. Elle n'a aucun rapport avec la paire de l'emetteur.
- Corollaire : l'emetteur doit signer en **asymetrique** (RS256). Si un jour il passait a un secret
  symetrique, ce service detiendrait la cle de signature et pourrait forger des tokens admin.

---

## Persistance : MongoDB, et ce que ca change

Le catalogue est stocke dans **MongoDB**, via **Doctrine ODM** : c'est un choix de persistance
polyglotte assume.

Le domaine ne s'en apercoit pas, et c'est la propriete a preserver. Les 155 tests a ports mockes
(76 `domain.catalog` + 46 `appli.catalog` + 33 `pres.state.catalog`) en sont le garde-fou.

L'etape B a rouvert `Application/` — mais pas pour la persistance. Les sept command handlers ont
gagne un argument `DomainEventBusInterface`, ce qui a impose de retoucher la suite `appli.catalog`
(47 tests aujourd'hui). C'est un **Port applicatif de plus**, pas une fuite d'infrastructure : la
regle « les suites a ports mockes passent sans modification » vise la persistance, et elle tient
toujours pour `domain.catalog` et `pres.state.catalog`, restees intactes.
Le seul endroit ou MongoDB est visible est `src/Infrastructure/Persistence/Mongo/`, plus les alias
de `config/services.yaml`.

### Le replica set n'est pas optionnel

**MongoDB refuse les transactions multi-documents sur un serveur standalone.** Retirer le replica set
ne provoquerait aucune erreur au demarrage : `MongoTransactional` deviendrait simplement une suite
d'ecritures independantes, et l'atomicite disparaitrait en silence.
`tests/Infrastructure/Integration/Persistence/MongoTransactionalTest.php` (suite `infra.persist`)
est ce qui s'en apercevrait : deux de ses cas ecrivent un document valide **puis** un document qui
viole un index unique, et verifient que le premier n'a pas survecu au rejet du second. Sans
transaction, il survit — ils passent au rouge.

### La limite de descripteurs non plus

Meme classe de reglage, meme mode de defaillance : Docker accorde **1 024** descripteurs par defaut,
MongoDB en demande **64 000**. Sous cette limite, mongod ne ralentit pas — il leve `TooManyFilesOpen`
en creant un eventfd, **segfault**, et redemarre. La suite fonctionnelle le declenchait de facon
fiable, et les tests tombaient alors sur un `ReplicaSetNoPrimary` qui ne designe pas la cause.
D'ou le bloc `ulimits.nofile` du service `mongodb` dans `docker-compose.yaml`.

C'est pourquoi `replication.replSetName` vit dans **`docker/mongodb/mongod.conf`**, versionne et
commente, et non dans un `command:` de docker-compose : un reglage dont depend l'atomicite des
ecritures ne doit pas tenir dans un argument de ligne de commande qu'un nettoyage ferait disparaitre
sans bruit. Le fichier est copie dans l'image (`docker/mongodb/Dockerfile`), pas monte en volume.

L'`rs.initiate()`, lui, reste dans le healthcheck du service : il ne peut avoir lieu qu'une fois le
serveur en ecoute. C'est un effet de bord dans une sonde — assume, en echange d'une vraie barriere
`depends_on: service_healthy` pour `app`.

Verification :

```bash
docker compose exec mongodb mongosh --quiet --eval \
  "const c = db.adminCommand({getCmdLineOpts: 1}); print(c.parsed.config + ' / ' + c.parsed.replication.replSetName + ' / ' + rs.status().members[0].stateStr)"
# /etc/mongod.conf / rs0 / PRIMARY
```

### `save()` ne flushe pas

L'ODM ne rend transactionnelles **que les operations d'un meme flush** — pas les requetes manuelles,
pas les agregations. Le contrat des repositories est donc le suivant :

- `save()` et `delete()` se contentent de `persist()` / `remove()` ;
- le flush unique est declenche par `MongoTransactional::transactional()` ;
- deux `save()` d'un meme cas d'usage (creer un produit + incrementer le compteur de sa categorie)
  commitent donc ensemble, ou pas du tout.

Ajouter un `flush()` dans un repository casserait cette garantie sans faire echouer un seul test
existant. Ne pas le faire.

### Les lectures sont hors transaction

`findByTitle()` dans un handler est un check-then-act : il ne protege de rien face a une ecriture
concurrente. Ce sont les **index uniques** declares dans le mapping des documents qui garantissent
reellement l'unicite des titres et des slugs. Ils ne sont poses par aucune migration : `make db-index`.

### Fixtures

`make fixtures` charge le catalogue de developpement : **30 categories** sur 4 niveaux (2 / 4 / 8 / 16)
et **1000 produits**, dont les 8 visuels de reference. La commande **purge la base** —
d'ou le groupe `dev`, qui n'est joue nulle part ailleurs. La suite de tests, elle, n'y touche pas :
elle vit dans `service_shop_test` et pose son propre jeu.

Ce jeu n'est **pas** une fixture : `BaseTest::seedCatalog()` ecrit 6 categories et 6 produits via les
repositories et `MongoTransactional`, a chaque test, apres un `deleteMany()`. Passer par
les agregats plutot que par des documents evite le piege decrit plus bas — sauf pour `nbProduct`, que
seul le cas d'usage maintient et que le seed doit donc incrementer lui-meme. Sans cela, le premier
`DELETE` de produit echoue sur « Product count cannot be negative ».

**Ne jamais remettre un `drop()` dans la remise a zero d'un test.** Il emporte les index, qu'il faut
alors reposer : mesure faite, `drop()` + `ensureIndexes()` coute **155 ms** par test la ou un
`deleteMany()` en coute **0,8**. C'etait 90 % du temps de la suite — le seed programmatique complet,
lui, ne pese que 16 ms. Les index sont poses **une fois par processus** ; ils doivent exister, sans
quoi les tests de conflit de titre et les deux cas de rollback de `MongoTransactionalTest` passeraient
au vert sans rien prouver.

Trois proprietes du seed sont contraintes par les assertions et ne doivent pas etre reduites sans
relire les tests : **6 categories** (la pagination est testee en `page=3&itemsPerPage=2`), une
categorie d'ancrage dotee d'**un parent, d'un enfant et d'une description** (le serializer omet les
valeurs nulles, donc `parent`/`children`/`description` disparaitraient du JSON), et des produits
**porteurs d'une image** (sans quoi `imageUrl` est nul, donc absent).

Le bundle ODM fournit sa propre chaine (`doctrine:mongodb:fixtures:load`, base
`Doctrine\Bundle\MongoDBBundle\Fixture\Fixture`, tag `doctrine.fixture.odm.mongodb`) :
`doctrine/doctrine-fixtures-bundle` est specifique a l'ORM et n'est **pas** installe ici.

Deux points a retenir avant d'en ecrire d'autres :

- **Une fixture ecrit des documents, pas des agregats.** Elle court-circuite donc les repositories,
  et doit poser elle-meme le `nbProduct` denormalise. Gedmo Tree calcule `path` et `level` au
  flush a partir de la relation `parent` ; une fixture ne doit pas les ecrire elle-meme.
- Les collections portent un index unique sur `title` **et** sur `slug`. Les titres sont tires en
  `unique()`, et `DataFixturesTrait::uniqueSlug()` suffixe les collisions de slug — sinon la fixture
  echouerait une fois sur dix, au hasard du tirage.

Les fixtures sont enregistrees sous `when@dev` uniquement (`config/services.yaml`) : elles dependent
de `fakerphp/faker`, une dependance de dev, et les declarer inconditionnellement casserait la
compilation du conteneur apres un `composer install --no-dev`.

### Ce que la base ne fait plus pour nous

MongoDB n'a ni cle etrangere ni cascade. Une categorie ne peut donc etre supprimee que si elle ne
porte aucun produit et n'a aucun enfant : `Category::delete()` fait respecter cet invariant avant
que le repository ne retire son seul document. Gedmo Tree maintient le materialized path, le niveau
et la propagation vers les descendants pendant le flush transactionnel. Le parent est une relation
ODM `ReferenceOne` stockee comme identifiant ; il n'existe pas de second champ `parentId` persiste.

---

## Domain Events : un outbox qui doit rejoindre le flush

> Cycle de vie complet — evenements, transport, worker, idempotence : [`docs/domain_events.md`](docs/domain_events.md).
> Cache de lecture et invalidation : [`docs/query_cache.md`](docs/query_cache.md).

Les douze evenements du catalogue sont enregistres par les agregats, publies par les command
handlers **dans** le callback transactionnel, et consommes par un worker Messenger.

```php
$this->transactional->transactional(function () use ($product): void {
    $this->productRepository->save($product);
    $this->eventBus->publishAll($product->releaseEvents());   // meme flush
});
```

### La regle qui ne doit jamais bouger

`MongoDomainEventBus` **`persist()` un document**. Il ne dispatche pas, il n'insere pas par le
pilote. C'est ce qui met la ligne d'outbox dans le flush unique de `MongoTransactional`, donc dans
la transaction de l'agregat.

Une ecriture emise a cote du flush n'a pas la session de la transaction : elle
s'engage seule, survit au rollback, et **rien ne le signale**. Les evenements arriveraient dans la
collection, le worker les consommerait, aucune erreur nulle part — seul un rollback publierait un
`CategoryCreatedEvent` pour une categorie qui n'existe pas.

`tests/Infrastructure/Integration/Persistence/DomainEventOutboxTest.php` est ce qui s'en apercoit :
deux de ses cas ecrivent un agregat valide puis provoquent l'echec du flush, et verifient que
l'outbox est vide. Remplacer le `persist()` par un `insertOne()` les fait passer au rouge, et rien
d'autre dans la suite ne bronche.

### Le transport est maison, et son `send()` n'est pas le chemin nominal

`mongodb-outbox://<file>` (`MongoOutboxTransport`) lit la collection par `findOneAndUpdate`
atomique, ce qui rend deux workers surs. Il implemente aussi `ListableReceiverInterface`, donc
`messenger:failed:show` et `messenger:failed:retry` fonctionnent.

Son `send()` **flushe** — ce qui serait une faute dans le chemin nominal. Il ne sert qu'aux chemins
de reprise (retry differe, copie vers la file d'echec, rejeu manuel), qui s'executent tous hors
transaction metier et exigent une ecriture durable immediatement.

### Ce que le worker fait reellement

Deux processus Supervisor consomment `domain_events`. Aujourd'hui, `LogDomainEventHandler`, type
sur `DomainEventInterface`, journalise tout.

Chaque image de produit a un nom aleatoire et appartient a un seul produit. Les handlers de commande
suppriment l'ancienne image apres le commit MongoDB. Le systeme de fichiers reste ainsi hors
transaction, sans worker ni comptage de references.

### L'invalidation de cache ne passe pas par le worker

Elle vit dans `CacheInvalidationMiddleware`, sur `command.bus`, et purge les tags **apres le commit
et avant la reponse**. Confiee au worker, elle arriverait quelques dizaines de millisecondes trop
tard : le client qui relit juste apres son ecriture ne verrait pas sa propre modification.

Tout fait du catalogue purge les **deux** collections, parce que les read models se citent : un
`ProductItem` porte le titre de sa categorie, un `CategoryItem` porte `nbProduct`.

---

## Pieges rencontres (ne pas les re-decouvrir)

- **`read: false` sur les operations sans `provider:`.** `stateOptions(entityClass:)` ne servait pas
  qu'a l'OpenAPI : il alimentait le ReadProvider par defaut d'API Platform pour les operations
  `Patch` et `Delete`, qui n'ont pas de provider explicite. En le retirant avec l'ORM, ces
  operations repondaient **404** avant meme d'atteindre leur processor. Le correctif est `read: false`.
- **Les attributs `Groups` d'une ressource ne servent a rien sans `normalizationContext`.**
  `CategoryResource` et `ProductResource` declaraient soigneusement `shop_*:read` et
  `shop_*:item:read`, mais aucune operation n'activait de groupe : le serializer exposait donc
  **tout**, et les collections laissaient fuiter `description`, `subtitle` et `updatedAt` — champs
  que le contrat de lecture documente reserve a la vue d'item. Aucune erreur, juste des payloads
  trop bavards. Les contextes sont desormais declares operation par operation.
- **Les tokens de test sont forges localement**, avec la paire `config/jwt/test/` declaree sous
  `when@test` dans `lexik_jwt_authentication.yaml`. Sortir ce bloc de `when@test` donnerait a dev et
  prod la capacite d'emettre des tokens admin, et retirerait au service la propriete qui justifie
  toute son architecture de validation.
- **API Platform active son integration ODM des qu'il voit le bundle Doctrine MongoDB**, et exige
  alors `api-platform/doctrine-odm`. On la coupe (`doctrine_mongodb_odm: false`) : toutes les
  ressources passent par des State Providers ecrits a la main.
- **L'outbox ne peut pas etre un transport Messenger ordinaire.** C'est le piege central de
  l'etape B. Sur Doctrine ORM, `doctrine://` emet son INSERT sur la connexion courante et rejoint
  donc la transaction ouverte sans que personne ait rien a faire. Une transaction MongoDB, elle,
  appartient a la session portee par le flush de l'ODM : une ecriture emise par le pilote a cote de
  ce flush s'engage seule et **survit au rollback de l'agregat**. `MongoDomainEventBus` fait donc
  `persist()`, jamais `insertOne()`, et le `send()` du transport ne sert qu'aux chemins de reprise.
  `DomainEventOutboxTest` est ce qui s'en apercevrait — remplacer le `persist()` par une ecriture
  directe fait passer au rouge ses deux cas de rollback, et rien d'autre.
- **Le cache des queries et son invalidation sont indissociables.** `QueryCacheMiddleware` seul
  servirait des lectures perimees des la premiere ecriture. Les deux ont ete branches ensemble, et
  ne doivent jamais etre desactives separement.
- **`sync://` ne sert a rien pour les tests de cet outbox.** `sync://` agit au `send()`, et le
  chemin nominal n'en emet pas. Les evenements iraient
  quand meme dans la collection.
- **Les evenements portent un `BusNameStamp('event.bus')`.** Le chemin nominal n'etant pas un
  dispatch, personne ne le pose pour nous. Sans lui, le `RoutableMessageBus` du worker retombe sur
  le bus par defaut — `command.bus`, qui n'a aucun handler d'evenement.
- **Le conteneur compile de l'environnement `test` n'est pas invalide par un changement de source.**
  `test` tourne en `debug=false` : Symfony ne surveille pas les fichiers, et un constructeur qui gagne
  un argument produit un `ArgumentCountError` ou un `TypeError` a l'execution — jamais une erreur de
  compilation. Le symptome est trompeur : des dizaines de tests d'API tombent en **500** avec des
  messages qui accusent le code applicatif, alors que seul le cache est perime. Reflexe apres toute
  modification de signature ou de `services.yaml` : `bin/console cache:clear --env=test`. C'est aussi
  ce qui peut faire passer une suite au vert sur un conteneur qui ne correspond plus au code.
- **`getPreferredLanguage()` rend `null` sans en-tete `Accept-Language`.** `kernel.enabled_locales`
  est vide dans ce service ; Gedmo refuse alors la locale vide et `LocaleListener` faisait repondre
  **500 a `GET /health`** — c'est-a-dire a tout appel `curl` et a toute sonde de supervision.
  Le repli sur `app.default_locale` n'est pas cosmetique.

- **`symfony/runtime` est obligatoire.** Sans lui, `FrameworkBundle::boot()` prend la branche
  `ErrorHandler::register(null, false)` et enregistre un gestionnaire d'exceptions global a chaque
  boot, sans jamais le restaurer — PHPUnit 11 marque alors tous les tests comme *risky*. Ne pas
  « corriger » cela avec `failOnRisky="false"`.
- **Pas de Symfony Flex** dans ce squelette : la configuration est ecrite a la main et reste
  deterministe. Consequence, `KERNEL_CLASS` doit etre declare a la main dans `phpunit.dist.xml`.
- **`secret_key` n'est pas requis** par `lexik/jwt-authentication-bundle` en validation seule :
  `public_key` suffit. Verifie en conditions reelles.
- **Le prefixe de route de l'emetteur est `/api/auth/…`**, pas `/…` : l'endpoint de login est
  `POST /api/auth/login` et il renvoie le champ **`accessToken`** (pas `token`).
- **Un `git commit` peut echouer sur des tests d'API verts en direct.** grumphp lance ses onze
  taches **en parallele** : `phpstan`, `rector` et `phpcsfixer` ecrivent dans `var/` pendant que
  phpunit amorce son noyau. Observe au jalon 3 — 22 erreurs et 15 echecs sur `api.catalog.*`, tous
  du meme type (`getInstance()` rendant `null` dans un `setUp`, donc un seed qui n'a rien produit),
  avec vert / rouge / vert sur trois executions successives. Ce n'est pas un probleme de donnees ni
  de code applicatif. Reflexe : relancer `vendor/bin/phpunit` seul pour trancher avant de chercher
  quoi que ce soit dans `src/`.

---

## Commandes

Tout s'execute dans le conteneur `app` — ne jamais lancer `composer` ou `bin/console` sur l'hote.

```bash
cp makefile.conf.dist makefile.conf   # prerequis, une fois
cp .env.dist .env                     # puis renseigner APP_SECRET

make install          # build + up + vendors + cles de test + index Mongo
make up / make down
make unit             # toute la suite
make unit-suite s=... # une suite (cf. phpunit.dist.xml)
make unit-filter f=...# une classe ou une methode
make bash-app
make bash-db          # shell mongosh
make db-index         # (re)pose les index declares dans le mapping ODM
make consume          # depile l'outbox a la main (Supervisor le fait deja en conteneur)
make fixtures         # catalogue de dev : 30 categories, 1000 produits (PURGE la base)
make jwt-public-key   # recopie la cle publique de l'emetteur
make jwt-test-keys    # regenere la paire de test
```

### Suites de tests

**`phpunit.dist.xml` est la reference et doit decrire l'integralite de `tests/`.** Un `phpunit.xml`
local est gitignore et peut le surcharger, mais une suite qui n'existerait que la-bas ne serait
jouee nulle part en CI — et l'oubli passerait pour un run vert.

| Suite | Repertoire | Touche Mongo |
|---|---|---|
| `domain.catalog` | `tests/Domain/Catalog/Unit` | non |
| `appli.catalog` | `tests/Application/Unit/Catalog/UseCase` | non |
| `pres.state.catalog` | `tests/Presentation/Unit/State/Catalog` | non |
| `infra.symfony.command` | `tests/Infrastructure/Unit/Symfony/Command` | non |
| `infra.api-platform.encoder` | `tests/Infrastructure/Unit/ApiPlatform/Encoder` | non |
| `infra.api-platform.serializer` | `tests/Infrastructure/Unit/ApiPlatform/Serializer` | non |
| `infra.symfony.messenger` | `tests/Infrastructure/Unit/Symfony/Messenger` | non |
| `infra.adapter.catalog` | `tests/Infrastructure/Unit/Adapter/Catalog` | non |
| `domain.shared` | `tests/Domain/SharedKernel/Unit` | non |
| `appli.shared` | `tests/Application/Unit/Shared` | non |
| `pres.state.shared` | `tests/Presentation/Unit/State/Shared` | non |
| `infra.persist` | `tests/Infrastructure/Integration/Persistence` | **oui** |
| `api.catalog.category` | `tests/Presentation/Api/Catalog/CategoryTest.php` | **oui** |
| `api.catalog.product` | `tests/Presentation/Api/Catalog/ProductTest.php` | **oui** |

### Les trois garde-fous ne testent aucun comportement

`domain.shared`, `appli.shared` et `pres.state.shared` portent `DomainTestCoverageTest`,
`HandlerConventionTest` et `StateTestCoverageTest` : ils balayent `src/` et cassent le build des
qu'un `Model`, un Value Object, un State ou un cas d'usage est livre **sans test**, ou qu'un handler
s'ecarte de la convention de nommage sur laquelle repose le routage Messenger.

Ils ont ete poses au jalon 3, avant l'arrivee de `Customer` et `Ordering`, et leur premier run a
trouve ce que personne n'avait vu : `Money`, `Slug` et `Uuid` du `SharedKernel` etaient arrives au
jalon 2 **sans leurs tests**. C'est exactement ce qu'ils servent a empecher.

Ils n'ont **aucune liste d'exclusion**. Une trappe pre-percee finit toujours par servir ; en
rajouter une doit rester un geste visible en diff, pas remplir un trou deja pret.

Attention : **la tache `phpstan` de grumphp n'analyse que les fichiers suivis par git.** Un fichier
neuf non encore `git add` passe au vert sans avoir ete regarde. Lancer
`vendor/bin/phpstan analyse -c phpstan.dist.neon` a la main avant de conclure.

Si `domain.catalog` ou `pres.state.catalog` doit etre retouchee pour passer au vert, c'est que la persistance a fuite hors
d'`Infrastructure/`. `appli.catalog` a ete retouchee une fois, a l'etape B, pour un Port de plus
(`DomainEventBusInterface`) — pas pour une fuite ; voir la section « Persistance ».

Les trois dernieres ecrivent dans `service_shop_test` : ce sont elles qui attrapent les regressions
de mapping, d'index et de transaction, et elles exigent la stack `make up` avec son replica set.
`infra.persist` porte les deux gardes qui ne se voient nulle part ailleurs — atomicite du flush
(`MongoTransactionalTest`) et atomicite de l'outbox (`DomainEventOutboxTest`).

---

## Verification bout en bout

```bash
# 1. Le service vit seul
curl localhost:20910/health                     # 200
curl localhost:20910/ping                       # 401

# 2. Avec un token reel de l'emetteur
TOKEN=$(curl -s -X POST localhost:20900/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"venom@en-develop.fr","password":"userVenom1@"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['accessToken'])")

curl -H "Authorization: Bearer $TOKEN" localhost:20910/ping    # 200 + userId/roles
curl -H "Authorization: Bearer ${TOKEN%?}X" localhost:20910/ping  # 401

# 3. Le catalogue, avec le meme token
CAT=$(curl -s -X POST localhost:20910/api/shop/categories -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"title":"Guitares"}')
CATID=$(echo "$CAT" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")

curl -X POST localhost:20910/api/shop/products -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"title\":\"Stratocaster\",\"subtitle\":\"Corps aulne\",\"description\":\"Six cordes\",\"price\":1299.90,\"category\":\"/api/shop/categories/$CATID\"}"   # 201

curl localhost:20910/api/shop/categories/$CATID   # nbProduct = 1 : les deux agregats ont commite ensemble
curl -X DELETE localhost:20910/api/shop/categories/$CATID -H "Authorization: Bearer $TOKEN"  # 204, produit supprime avec

# 4. Les Domain Events ont suivi le meme commit, et le worker les a depiles
docker compose exec app bin/console messenger:stats          # domain_events = 0, failed = 0
docker compose exec app sh -c 'grep "Domain event handled" var/log/dev.log | tail -5'
# shop.catalog.category.created / product.created / category.deleted / product.deleted

# 5. Le cache de queries est servi puis invalide par l'ecriture
reads() { docker compose exec -T app sh -c 'grep -c "MongoDB command: {\"find\":\"category\"" var/log/dev.log'; }
A=$(reads); curl -s -o /dev/null 'localhost:20910/api/shop/categories?page=1&itemsPerPage=3'
B=$(reads); curl -s -o /dev/null 'localhost:20910/api/shop/categories?page=1&itemsPerPage=3'
C=$(reads); echo "miss=$((B-A)) hit=$((C-B))"   # miss > 0, hit = 0
```

---

## Prochains jalons

- **Jalon 3, etape A — fait.** `Customer` et `Cart` sont ici, adresses embarquees, verrou optimiste,
  Domain Events.
- **Jalon 3, etape B — a faire, et c'est le trou du moment.** **Plus rien ne cree de client
  automatiquement.** Cote `service_identity`, `ProvisionCustomerHandler` et `DisableCustomerHandler`
  reagissent toujours aux evenements `User`, mais derriere `ShopCustomerClientInterface`, dont
  l'unique adaptateur journalise sans rien faire. Un compte cree aujourd'hui n'a de client ici que
  si un admin appelle `POST /api/shop/customers`.
  Reste a choisir le protocole inter-services (HTTP, gRPC, message) et a ecrire l'adaptateur — plus,
  cote ce service, `ROLE_SERVICE` hors `role_hierarchy`, la securite de `ShopCustomer` au niveau
  operation, et un endpoint de desactivation clee sur `userAccountId`.

---

## Checklist

- [ ] Aucune cle privee dans le depot (`ls config/jwt/private.pem` doit echouer).
- [ ] Aucun acces a la base de `service_identity` ni broker sans justification metier ; Redis reste dedie au
      cache applicatif partage entre les replicas de ce service.
- [ ] `GET /health` repond sans que la stack de `service_identity` tourne.
- [ ] `make unit` vert, sans test *risky*.
- [ ] Aucune reference a Doctrine hors `src/Infrastructure/Persistence/` (`grep -rn Doctrine src/`).
- [ ] Aucun `flush()` dans un repository — seul `MongoTransactional` flushe. Le transport de
      l'outbox est la seule exception, et seulement dans son `send()`, hors transaction metier.
- [ ] Les Domain Events sont publies **dans** le callback transactionnel, apres le `save()`.
- [ ] `MongoDomainEventBus` `persist()` — jamais d'ecriture par le pilote dans le chemin nominal.
- [ ] Le cache des queries et son invalidation sont actifs tous les deux, ou aucun des deux.
- [ ] Les suites a ports mockes passent **sans modification** (cf. tableau des suites).
- [ ] Toute suite declaree dans un `phpunit.xml` local existe aussi dans `phpunit.dist.xml`.
- [ ] `declare(strict_types=1);` dans tout nouveau fichier PHP.
