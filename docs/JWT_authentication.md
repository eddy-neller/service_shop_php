# Authentification JWT : ce que le shop vérifie, et comment

## En bref

```text
identity ──── délivre un token signé ────▶ client ──── l'envoie à chaque requête ────▶ shop
                                                                          le vérifie seul,
                                                                  avec la clé publique d'identity
```

- **Un token, c'est un badge signé.** Il dit à quel compte il appartient (`sub`) et quels sont ses
  rôles (`roles`), et il expire au bout de 15 minutes.
- **Le shop vérifie le badge tout seul.** Il contrôle la signature avec la clé publique d'identity
  et la date d'expiration, sans jamais appeler identity. Si identity tombe, les badges déjà
  délivrés restent acceptés jusqu'à leur expiration.
- **Badge absent ou invalide : 401.** Badge valide mais rôle insuffisant : 403. Le catalogue se lit
  sans badge, son écriture exige `ROLE_ADMIN`, le panier et les adresses demandent seulement d'être
  connecté.
- **Le shop ne peut pas fabriquer de badge.** Il n'a que la clé publique ; seule identity a la clé
  privée.
- **La clé n'est pas dans l'image Docker.** Elle est fournie au démarrage du conteneur, comme un
  secret.
- **Aujourd'hui, changer de clé se fait des deux côtés en même temps.** Le shop ne connaît qu'une
  clé, recopiée depuis identity : si identity en change, le shop doit redémarrer avec la nouvelle au
  même moment. **La cible est JWKS** : le shop ira chercher lui-même les clés d'identity, et ce
  problème disparaîtra.
- **Limite connue : un compte bloqué garde l'accès jusqu'à 15 minutes.** Exception : un compte
  supprimé perd tout de suite l'accès à son panier et à ses adresses, grâce au bus.

Le reste du document détaille chaque point. Pour une question précise :

| Question | Section |
|---|---|
| Que se passe-t-il exactement à chaque requête ? | « Le chemin d'une requête authentifiée » |
| Que contient le token, et qu'en lit le shop ? | « Le contrat du token, vu d'ici » |
| Où sont les clés, en dev et en prod ? | « Les clés : d'où elles viennent, où elles vont » |
| Comment changer de clé, et ce que JWKS changera ? | « Changer de clé : aujourd'hui, et demain avec JWKS » |
| Pourquoi un compte bloqué garde-t-il l'accès ? | « Révocation : la fenêtre de 900 s » |
| Qu'est-ce qui est testé ? | « Les tests » |
| Qu'est-ce qui manque encore ? | « Ce qui reste à faire » |

---

> Côté **validation** uniquement. L'émission, les refresh tokens et `auth_version` sont décrits par
> l'émetteur : [`../../service_identity/docs/JWT_authentication.md`](../../service_identity/docs/JWT_authentication.md).
> Les règles à ne pas enfreindre, en version courte : [`../AGENTS.md`](../AGENTS.md), « Règles sur les
> clés » et « Décision d'architecture : fenêtre de révocation de 900 s ».

## Ce que ce service est, et ce qu'il refuse d'être

Le shop est un **resource server** au sens d'OAuth 2.0 : il reçoit un access token émis par
`service_identity`, le vérifie **lui-même, localement**, et décide de ce que son porteur peut faire.
C'est le modèle standard (Keycloak, Auth0, Cognito ou Spring Security font de même), et trois
propriétés en découlent. Ce sont elles qui comptent, plus que le code :

- **Aucun appel à l'émetteur.** La vérification ne demande que la clé publique. `service_identity`
  éteint, le shop continue de servir tous les tokens encore valides : l'authentification n'est pas
  une dépendance d'exécution.
- **Incapable d'émettre.** Le shop ne détient que la clé **publique** et ne pourra jamais forger un
  token, même compromis. C'est pourquoi l'émetteur doit rester en signature **asymétrique** (RS256) :
  avec un secret symétrique, ce service détiendrait la clé de signature.
- **Sans état.** Le firewall est `stateless`, la session est désactivée, et aucun état
  d'authentification n'est partagé avec l'émetteur : ni base, ni Redis commun.

## Le chemin d'une requête authentifiée

```text
client ── Authorization: Bearer <jwt>
   │
   ▼
Kong                          route par préfixe, ne vérifie AUCUN token
   │
   ▼
Varnish (production)          `Authorization` présent ──▶ pass : jamais mis en cache
   │
   ▼
nginx ──▶ php-fpm ──▶ firewall `main` (stateless, authenticator `jwt`)
   │
   ▼  Lexik
extraction                    en-tête `Authorization: Bearer` uniquement (ni cookie, ni query)
signature                     RS256 imposé par la config, clé publique de l'émetteur
validité                      `exp` obligatoire, aucune tolérance d'horloge (`clock_skew: 0`)
   │
   ▼
JwtAuthenticatedUser          `sub` doit être un UUID, `roles` une liste de chaînes
   │
   ▼
role_hierarchy ──▶ expressions `security` d'API Platform
   │
   ▼  opérations `/shop/me/…` seulement
CurrentCustomerResolver       `sub` ──▶ client du compte
```

Quelques points de ce schéma ne se voient pas à la lecture du code.

**Kong ne vérifie rien, et c'est voulu.** Si l'authentification se terminait à la passerelle, avec
un en-tête d'identité injecté (`X-User-Id`) auquel le service ferait confiance, n'importe qui
joignant le port publié du shop forgerait une identité avec un `curl -H`. Un plugin JWT dans Kong
reste possible, mais **en plus** de cette vérification, jamais à sa place.

**L'algorithme vient de la configuration, pas du token.** Lexik vérifie avec le signataire RS256
configuré et refuse un en-tête `alg` différent. C'est ce qui fait échouer un token `alg: none`,
cas couvert par `testUnsignedTokenIsRejected`.

**Varnish ne voit jamais un token.** Toute requête portant `Authorization` ou `Cookie` est
transmise sans être stockée (`docker/varnish/conf/default.vcl`). Corollaire : un GET public du
catalogue envoyé *avec* un token contourne le cache. C'est correct, mais plus lent.

**`JwtAuthenticatedUser` n'est pas un utilisateur chargé depuis une base.** Il est reconstruit à
partir des claims à chaque requête : aucun appel à MongoDB, et le shop n'a de toute façon aucune
table d'utilisateurs. Un `sub` qui n'est pas un UUID, ou des `roles` qui ne sont pas une liste de
chaînes, lèvent `InvalidTokenException`, donc une réponse 401.

### Ce que répond le service

Réponses observées sur le service en marche :

| Situation | Statut | Corps |
|---|---|---|
| Aucun token | 401 | `{"code":401,"message":"JWT Token not found"}` |
| Token illisible, mal signé, expiré, réécrit | 401 | `{"code":401,"message":"Invalid JWT Token"}` |
| Token valide, rôle insuffisant | 403 | — |
| `/shop/me/…`, client pas encore provisionné par le bus | 404 | `CustomerNotFoundException` |
| `/shop/me/…`, client désactivé (compte supprimé) | 403 | `CustomerDisabledException` |

## Le contrat du token, vu d'ici

Claims d'un vrai token émis par `service_identity`, en-tête `{"typ":"JWT","alg":"RS256"}` :

| Claim | Lu par le shop | Usage |
|---|---|---|
| `sub` | **oui** | UUID du compte : identité, et clé vers le client (`userAccountId`) |
| `roles` | **oui** | autorisation : `role_hierarchy`, puis expressions `security` |
| `exp` | **oui** | obligatoire ; un token expiré est refusé à la seconde près |
| `iat` | vérifié s'il est présent | un `iat` dans le futur est refusé |
| `auth_version` | **non, volontairement** | voir « Révocation » plus bas |

**Absents aujourd'hui : `iss`, `aud` et `kid`.** Le shop accepte donc tout token signé par une clé
qu'il connaît, quelle que soit sa destination. C'est sans effet tant que l'émetteur ne produit
qu'un seul type de token, mais ça cesse de l'être au premier token destiné à autre chose. Voir
« Ce qui reste à faire ».

### `roles` fait partie du contrat, au même titre que les événements du bus

Le shop autorise à partir des rôles de l'émetteur :

| Où | Exigence |
|---|---|
| Écritures du catalogue (`CategoryResource`, `ProductResource`) | `ROLE_ADMIN` |
| `CustomerResource` (administration des clients) | `ROLE_ADMIN` |
| Champs réservés aux admins (`ApiProperty`, groupe `AdminGroup`) | `ROLE_ADMIN` |
| `/shop/me/…` (panier, adresses) | `IS_AUTHENTICATED_FULLY` : n'importe quel token valide |
| Lectures du catalogue | publiques |

Les noms des rôles vivent dans `RoleSet`, et la `role_hierarchy` de `config/packages/security.yaml`
est **identique, ligne pour ligne, à celle de l'émetteur**. Ce couplage ne se voit nulle part :
renommer ou restructurer un rôle chez `service_identity` casse l'autorisation ici sans qu'aucune
suite ne rougisse, dans l'un ou l'autre dépôt. Tant que ce point n'est pas traité, **toute
modification de rôle chez l'émetteur se livre en même temps ici**.

## Les clés : d'où elles viennent, où elles vont

Le shop fait confiance à **une** clé publique, celle de l'émetteur. C'est une donnée du
**déploiement**, jamais de l'image :

| Variable | Développement | Production (`make up-prod`) |
|---|---|---|
| `JWT_PUBLIC_KEY` | `config/jwt/public.pem` | secret `/run/secrets/jwt_public_key` |

**En développement**, `make jwt-public-key` recopie la clé de l'émetteur depuis
`JWT_ISSUER_PUBLIC_KEY`, défini dans `makefile.conf`. Le Makefile versionné ne connaît pas
l'emplacement de `service_identity` : c'est une donnée du poste. La cible refuse une source
absente, un fichier qui n'est pas une clé publique PEM, et **tout fichier contenant une clé
privée**.

**En production**, `.dockerignore` exclut tout `config/jwt/` : l'image ne contient aucune clé.
`docker-compose.prod.yaml` la monte au démarrage en secret Compose, lu depuis `JWT_PUBLIC_KEY_FILE`
(par défaut `./config/jwt/public.pem`). C'est déjà la forme d'un Secret Kubernetes monté en
fichier. Deux conséquences :

- une image lancée **sans** clé montée refuse tous les tokens. C'est voulu : la clé n'est pas un
  artefact du build ;
- une source absente fait échouer la **création du conteneur** (« bind source path does not
  exist »), mais **pas** `docker compose config`, qui ne vérifie rien.

## Changer de clé : aujourd'hui, et demain avec JWKS

**Aujourd'hui, le changement est coordonné.** Le shop ne connaît qu'une clé. Si `service_identity`
change de clé de signature, le shop doit redémarrer avec la nouvelle **au moment** où l'émetteur
bascule, sans reconstruire l'image puisque la clé est montée au démarrage :

```bash
JWT_PUBLIC_KEY_FILE=./config/jwt/next.pem make up-prod   # recrée `app` et `worker`
```

Entre les deux bascules, les tokens signés par l'autre clé sont refusés (401). Les clients
récupèrent un nouveau token en passant par le refresh de l'émetteur, mais la fenêtre existe. C'est
le couplage de déploiement qui reste entre les deux services.

**La cible est JWKS, décidée le 2026-10-23.** L'émetteur publiera ses clés publiques à une adresse
standard (`/.well-known/jwks.json`) et signera chaque token avec un `kid`, l'identifiant de la clé
utilisée. Le shop lira ce jeu de clés lui-même, le gardera en cache dans **son** Redis, et choisira
la bonne clé d'après le `kid`. Changer de clé ne demandera alors plus rien au shop : l'émetteur
ajoute la nouvelle clé au jeu, attend le renouvellement des caches, signe avec elle, puis retire
l'ancienne.

Ce sera une **exception explicite** à l'invariant « pas d'appel HTTP est-ouest » : des clés
publiques, mises en cache, lues hors du chemin des requêtes, de préférence par l'adresse publique.
Le plan et ce qu'il demande à chaque service : `../../ARCHITECTURE.md`, « Vers JWKS ».

D'ici là, **ne pas réinvestir dans la copie de clé** : pas de seconde clé de confiance, pas de
procédure de rotation manuelle. L'essai a été fait et retiré, et il a laissé un piège à connaître :
Lexik lit `additional_public_keys` à **chaque** vérification et lève une exception si un chemin est
vide ou illisible. Un emplacement « optionnel, vide par défaut » ferait refuser **tous** les tokens.

## Révocation : la fenêtre de 900 s

**Un compte révoqué garde l'accès au shop pendant au plus `JWT_TTL` (900 s).** C'est une décision,
pas un oubli. L'émetteur porte `auth_version` dans ses tokens pour révoquer immédiatement chez lui ;
le shop l'ignore, parce que le vérifier imposerait un état partagé entre les services (un Redis
commun) ou un appel à l'émetteur à chaque requête. La révocation qui fait autorité reste chez
l'émetteur : un compte révoqué n'obtient plus de **nouveau** token.

Le bus réduit déjà cette fenêtre dans le cas le plus grave. `identity.user.deleted` désactive le
client, et `CurrentCustomerResolver` répond alors 403 sur **toutes** les opérations `/shop/me/…`,
dans le délai de livraison du bus et non plus dans les 900 s.

Ce qui reste ouvert pendant la fenêtre :

- les opérations `ROLE_ADMIN` (catalogue, clients) d'un compte supprimé dont le token est encore
  valide ;
- un admin rétrogradé, qui garde `ROLE_ADMIN` jusqu'à l'expiration de son token ;
- un changement de mot de passe ou une « déconnexion de partout ».

Si la fenêtre devient un problème, la réponse respecte l'autonomie des services. L'émetteur publie
un fait `identity.user.sessions_revoked`, et le shop tient **dans son propre Redis** une liste
`sub → revokedAt` dont le TTL vaut `JWT_TTL`, puis refuse tout token dont l'`iat` est antérieur.
Pas d'état partagé, pas d'appel synchrone, et un type d'événement de plus ne casse pas le contrat
(le consommateur acquitte déjà les types inconnus). **Non fait** : c'est une évolution possible, pas
un manque.

## Les tests

La suite `api.jwt` (`tests/Presentation/Api/Shared/JwtAuthenticationTest.php`) frappe une vraie route
protégée, `GET /api/shop/customers`. Elle exige `ROLE_ADMIN` et API Platform évalue l'expression
**avant** le provider : un token refusé n'atteint jamais MongoDB, d'où l'absence de seeds.

| Cas | Attendu | Ce que le cas prouve |
|---|---|---|
| Aucun token | 401 | |
| Token valide, `ROLE_USER` | **403** | le **témoin** : les tokens forgés sont acceptés, donc chaque 401 tient au défaut injecté |
| Token expiré | 401 | `exp` est vérifié |
| Payload réécrit après signature | 401 | la signature couvre les claims |
| `alg: none` | 401 | l'algorithme vient de la config, pas du token |
| `sub` qui n'est pas un UUID | 401 | `JwtAuthenticatedUser` |
| `roles` qui n'est pas une liste | 401 | `JwtAuthenticatedUser` |
| Signé **à la main** avec la clé de test | **403** | le témoin du cas suivant : `signRs256()` fabrique des tokens valides |
| Signé par une clé **inconnue** | 401 | une signature RS256 valide ne suffit pas, il faut la bonne clé |

**Une paire de test, versionnée volontairement**, dans `config/jwt/test/`. Elle ne protège rien et
n'a aucun rapport avec la clé de l'émetteur ; `make jwt-test-keys` la régénère. La suite forge ses
tokens sans que l'émetteur tourne, avec l'encodeur Lexik (`test.jwt_encoder`), dont la clé privée
n'est déclarée que sous `when@test`. Les deux derniers cas signent à la main en RS256 : il faut bien
présenter une autre clé que celle de l'encodeur.

**Le témoin « signé à la main » a été vu rougir** : avec une signature volontairement cassée dans
`signRs256()`, il est le seul à échouer. Sans lui, le cas « clé inconnue » passerait même avec un
token mal fabriqué, sans rien prouver.

```bash
make unit-suite s=api.jwt
```

> `test` tourne en `debug=false` : après une modification de la config Lexik ou de `.env.test`, le
> conteneur compilé n'est pas invalidé. Lancer `bin/console cache:clear --env=test` avant de
> conclure quoi que ce soit d'un résultat.

## Vérification de bout en bout

Les deux services démarrés :

```bash
# 1. Un vrai token de l'émetteur est accepté : la clé publique du shop est la bonne
TOKEN=$(curl -s -X POST localhost:20900/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"venom@en-develop.fr","password":"userVenom1@"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["accessToken"])')
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" \
  localhost:20910/api/shop/customers                                        # 200 (compte admin)

# 2. Le shop vit sans l'émetteur : arrêter service_identity, puis rejouer la même requête
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN" \
  localhost:20910/api/shop/customers                                        # 200 jusqu'à `exp`

# 3. Aucune clé dans l'image de production
docker build -q --target prod -t shop_jwtcheck:prod -f docker/app/Dockerfile .
docker run --rm --entrypoint ls shop_jwtcheck:prod /var/www/config/jwt      # No such file or directory
docker rmi shop_jwtcheck:prod
```

## Ce qui reste à faire

Les écarts avec le standard OAuth 2.0, du plus utile au plus lointain :

1. **JWKS et `kid`** — **décidé**, pas encore fait. Supprime la copie de clé et le changement de
   clé coordonné ; voir « Changer de clé » et `../../ARCHITECTURE.md`, « Vers JWKS ».
2. **`iss` et `aud`.** L'émetteur les ajoute (`aud: ["service-shop"]`), le shop les vérifie dans un
   listener `JWT_DECODED`, le mécanisme que l'émetteur utilise déjà pour `auth_version`. Livrer le
   shop d'abord en tolérant leur absence, puis les rendre obligatoires. Ils touchent les mêmes
   fichiers que JWKS, des deux côtés : à faire avec lui.
3. **L'autorisation propre au shop.** Documenter `roles` comme partie du contrat, puis faire passer
   les expressions `security` par des voters du shop (`CATALOG_WRITE`…) qui traduisent les rôles de
   l'émetteur **à un seul endroit**. Un renommage de rôle ne toucherait plus qu'un fichier.
4. **Révocation par le bus**, seulement si la fenêtre de 900 s devient un problème (voir plus haut).

## Fichiers de référence

| Fichier | Rôle |
|---|---|
| `config/packages/lexik_jwt_authentication.yaml` | clé publique de l'émetteur, clé privée de test sous `when@test` |
| `config/packages/security.yaml` | firewall `stateless`, provider `lexik_jwt`, `role_hierarchy` |
| `src/Infrastructure/Symfony/Security/JwtAuthenticatedUser.php` | identité reconstruite depuis les claims |
| `src/Infrastructure/Symfony/Security/RoleSet.php` | noms des rôles utilisés par les expressions `security` |
| `src/Presentation/Shared/State/CurrentCustomerResolver.php` | `sub` ──▶ client, pour les opérations `/shop/me/…` |
| `docker-compose.prod.yaml` | clé montée en secret au démarrage |
| `.dockerignore` | `config/jwt/` exclu de l'image |
| `Makefile` (`jwt-public-key`, `jwt-test-keys`) | recopie de la clé de l'émetteur, paire de test |
| `tests/Presentation/Api/Shared/JwtAuthenticationTest.php` | suite `api.jwt` |
