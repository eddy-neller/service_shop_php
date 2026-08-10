# AGENTS.md — service_shop

> Guide pour humains **et** agents. Service d'extraction du bounded context Shop
> du monolithe `service_identity/`. Jalon 1 : authentification seule, aucun code metier.

---

## Perimetre : pile PHP uniquement

`back_php/` et `back_js/` sont **deux implementations paralleles et independantes du meme systeme**,
construites pour la montee en competence. Elles ne communiquent **jamais** entre elles.

Ce service appartient a la pile PHP. Son emetteur de tokens est `service_identity/`. `back_js/service_identity`
n'intervient a aucun moment — ne pas raisonner sur les deux piles a la fois.

---

## Ce que ce service est, et n'est pas

- **Est** : un consommateur de JWT. Il valide signature + `exp` et reconstruit l'identite depuis les
  claims.
- **N'est pas** : un emetteur. Il ne detient que la cle **publique**. Il est structurellement
  incapable de forger un token, et cette propriete doit etre preservee.
- **N'a pas** : de base de donnees, de broker, de cache distribue. Aucune de ces dependances ne doit
  etre ajoutee sans une raison metier explicite (la premiere sera Postgres, au jalon 2, avec Catalog).

Le service demarre et repond **seul**, sans la stack du monolithe. C'est une propriete verifiee par
`GET /health`, a preserver.

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

## Pieges rencontres (ne pas les re-decouvrir)

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

---

## Commandes

Tout s'execute dans le conteneur `app` — ne jamais lancer `composer` ou `bin/console` sur l'hote.

```bash
cp makefile.conf.dist makefile.conf   # prerequis, une fois
cp .env.dist .env                     # puis renseigner APP_SECRET

make install          # build + up + vendors + cles de test
make up / make down
make test             # suite fonctionnelle
make bash-app
make jwt-public-key   # recopie la cle publique de l'emetteur
make jwt-test-keys    # regenere la paire de test
```

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
```

---

## Prochains jalons

- **Jalon 2** — deplacer `Catalog` depuis le monolithe (verifie sans couplage a `Customer` /
  `UserAccountId`). C'est a ce moment qu'arrivent Postgres, Doctrine et API Platform.
- **Jalon 3** — `Customer` / `Cart` / `Ordering` : propriete des donnees, `UserAccountId` sans cle
  etrangere, saga de provisionnement (aujourd'hui `ProvisionCustomerHandler` cote monolithe).

---

## Checklist

- [ ] Aucune cle privee dans le depot (`ls config/jwt/private.pem` doit echouer).
- [ ] Aucune dependance a une base, un broker ou un cache sans justification metier.
- [ ] `GET /health` repond sans que la stack du monolithe tourne.
- [ ] `make test` vert, sans test *risky*.
- [ ] `declare(strict_types=1);` dans tout nouveau fichier PHP.
