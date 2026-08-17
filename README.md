# service_shop

Service autonome du bounded context **Shop**.

**Etat actuel — jalon 2, etape A : le catalogue.** Le service authentifie les porteurs de JWT emis
par `service_identity/` sans acceder a sa base, et sert desormais `Catalog` (produits et categories)
depuis **sa propre base MongoDB**.

## Demarrage

```bash
cp makefile.conf.dist makefile.conf
cp .env.dist .env            # renseigner APP_SECRET
make jwt-public-key          # copie la cle publique de l'emetteur
make install                 # build + up + vendors + cles de test + index Mongo
make fixtures                # catalogue de dev : 30 categories, 1000 produits
```

Le service ecoute sur **http://localhost:20910** (`service_identity` occupe 20900-20909).

## Endpoints

| Methode | Route | Acces | Role |
|---|---|---|---|
| GET | `/health` | public | prouve que le service vit **sans** la stack de `service_identity` |
| GET | `/ping` | authentifie | restitue `userId` + `roles` extraits du token |
| GET | `/shop/categories`, `/shop/categories/{id}` | public | lecture du catalogue |
| POST / PATCH / DELETE | `/shop/categories…` | `ROLE_ADMIN` | ecriture |
| GET | `/shop/products`, `/shop/products/{id}` | public | lecture, filtres `title`/`subtitle`/`description`/`category`, tri, pagination |
| POST / PATCH / DELETE | `/shop/products…` | `ROLE_ADMIN` | ecriture |
| POST | `/shop/products/{id}/image` | `ROLE_ADMIN` | upload de l'image produit |

## Architecture en deux phrases

Signature **RS256** : le service ne detient que la cle publique de l'emetteur, et
`src/Security/JwtAuthenticatedUser.php` reconstruit l'utilisateur depuis les seuls claims `sub` et
`roles` — aucun acces a la base de `service_identity`.

Le catalogue est persiste dans **MongoDB via Doctrine ODM**. La persistance est limitee a
`src/Infrastructure/Persistence/Mongo/` et aux alias de `config/services.yaml`.

## Points a connaitre avant de modifier quoi que ce soit

Lire [`AGENTS.md`](AGENTS.md) — notamment la **fenetre de revocation de 900 s** (choix delibere), la
regle absolue sur les cles privees, le fait que **`save()` ne flushe pas** (c'est `MongoTransactional`
qui commite), et pourquoi le **replica set MongoDB n'est pas optionnel**.

## Tests

```bash
make test
```

178 tests. Les suites `domain`, `application` et `presentation` tournent sur ports mockes. La suite `functional` est la seule a
toucher un vrai MongoDB : API bout en bout, index uniques, cascade de suppression, et l'atomicite
des transactions multi-documents.
