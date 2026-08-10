# service_shop

Extraction du bounded context **Shop** du monolithe `api/`, en service autonome.

**Etat actuel — jalon 1 : authentification uniquement.** Le service ne contient aucun code metier.
Il prouve qu'un service tiers peut authentifier une requete porteuse d'un JWT emis par `api/`, sans
acceder a sa base de donnees.

## Demarrage

```bash
cp makefile.conf.dist makefile.conf
cp .env.dist .env            # renseigner APP_SECRET
make jwt-public-key          # copie la cle publique de l'emetteur depuis api/
make install                 # build + up + vendors + cles de test
```

Le service ecoute sur **http://localhost:20910** (le monolithe occupe 20900-20909).

## Endpoints

| Methode | Route | Acces | Role |
|---|---|---|---|
| GET | `/health` | public | prouve que le service vit **sans** la stack du monolithe |
| GET | `/ping` | authentifie | restitue `userId` + `roles` extraits du token |

## Architecture en une phrase

Signature **RS256** : le service ne detient que la cle publique de l'emetteur, et
`src/Security/JwtAuthenticatedUser.php` reconstruit l'utilisateur depuis les seuls claims `sub` et
`roles`. D'ou l'absence totale de base de donnees.

## Points a connaitre avant de modifier quoi que ce soit

Lire [`AGENTS.md`](AGENTS.md) — notamment la **fenetre de revocation de 900 s** (choix delibere), la
regle absolue sur les cles privees, et les pieges de configuration deja resolus.

## Tests

```bash
make test
```

Cinq cas fonctionnels : `/health` public, `/ping` sans token, token valide, token expire, signature
alteree.
