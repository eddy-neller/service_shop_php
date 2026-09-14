# Verrous (Symfony Lock)

Les verrous vivent dans le **Redis du service, base 1**. Jamais dans `flock`, sauf en test. Ce
document explique pourquoi, parce que rien ne casse le jour où la règle est enfreinte : ni erreur, ni
test rouge — seulement deux processus qui croient chacun être seuls.

```yaml
# config/packages/lock.yaml
framework:
    lock: '%env(LOCK_DSN)%'     # .env.dist : LOCK_DSN="${REDIS_URL}/1"

when@test:
    framework:
        lock: 'flock'
```

## Ce qui prend un verrou aujourd'hui

Un seul consommateur : le `DeduplicateMiddleware` de Messenger, ajouté par Symfony au middleware par
défaut des trois bus (`command.bus`, `query.bus`, `event.bus`). Il ne verrouille **que** les messages
portant un `DeduplicateStamp` — et aucun n'en porte (vérifié le 2026-09-14 : aucune occurrence dans
`src/`, `tests/` ni `config/`).

Le store n'a donc aujourd'hui **aucun effet observable**. Il est configuré correctement quand même, pour
le jour où arrive le premier verrou réel : un message dédoublonné, une commande console
`LockableTrait`, une tâche planifiée. Avec `flock`, ce premier usage passerait tous les tests — ils
tournent dans un seul processus — et ne protégerait rien une fois déployé.

## Pourquoi Redis

Le service tourne à **plusieurs instances** : trois répliques `app` par défaut (`APP_REPLICAS`), plus
un conteneur `worker`. Un verrou n'a de sens que s'il est vu par **tous** les processus, dans **tous**
les conteneurs.

`flock` pose un verrou sur un fichier du système de fichiers local — le `/tmp` du conteneur. Chaque
réplique a le sien. Deux répliques obtiennent donc le même verrou **en même temps**, sans erreur.

Mesuré le 2026-09-14, même nom de verrou, deux conteneurs lancés à une seconde d'écart :

| `LOCK_DSN` | Conteneur 1 | Conteneur 2 | |
|---|---|---|---|
| `flock` | `app-2` : obtenu | `app-3` : **obtenu aussi** | chaque conteneur a son verrou |
| `redis://redis:6379/1` | `app-2` : obtenu | `worker-1` : **refusé** | un verrou pour tout le service |

C'est aussi ce qui garde la configuration valable sous Kubernetes : un pod n'est qu'une réplique de
plus, et Redis reste le point de rendez-vous (changement n°3 de la feuille de route, dans
`back_php/ARCHITECTURE.md`).

### Pourquoi la base 1

Le cache applicatif occupe la base 0 (`REDIS_URL` sans index). Les verrous sont en base 1 :

- purger le cache (`FLUSHDB` sur la base 0) n'emporte pas les verrous en cours ;
- `redis-cli -n 1 KEYS '*'` ne montre que des verrous, ce qui rend le diagnostic immédiat.

Deux propriétés du Redis du service comptent ici :

- **`maxmemory-policy noeviction`** (vérifié) : sous pression mémoire, Redis refuse les écritures
  plutôt que d'évincer des clés. Un verrou ne peut donc pas disparaître en silence.
- **Aucun volume** : un redémarrage de Redis perd les verrous en cours. C'est acceptable — un verrou
  est éphémère par nature, et son TTL existe précisément pour qu'aucun ne survive à son détenteur.

Ce Redis est **dédié** à ce service. Ne pas le mutualiser avec celui de `service_identity` : deux
services qui partagent un store de verrous peuvent se bloquer mutuellement sur une clé homonyme.

## Pourquoi `flock` en test

L'environnement de test ne dépend pas de Redis : le cache y est déjà en `cache.adapter.array`
(`config/packages/cache.yaml`). Les verrous suivent la même règle, et c'est le même choix que
`service_identity`, dont la CI ne dispose d'aucun Redis.

Les tests tournent dans un seul processus : `flock` y suffit.

**Conséquence : aucun test ne peut prouver l'exclusion entre répliques.** C'est une propriété du
déploiement, pas du code. Elle se vérifie à la main, stack démarrée :

```bash
cat > /tmp/lock_proof.php <<'PHP'
<?php
require '/var/www/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\StoreFactory;

(new Dotenv())->bootEnv('/var/www/.env');
usleep((int) (getenv('DELAY_MS') ?: 0) * 1000);

$lock = (new LockFactory(StoreFactory::createStore($_SERVER['LOCK_DSN'])))->createLock('proof-isolation', 30);
$ok = $lock->acquire(false);
printf("%s LOCK_DSN=%s obtenu=%s\n", gethostname(), $_SERVER['LOCK_DSN'], $ok ? 'OUI' : 'non');
if ($ok) { sleep(4); $lock->release(); }
PHP

docker exec -i en_shop_php_service_shop-app-2 php < /tmp/lock_proof.php &
docker exec -i -e DELAY_MS=1000 en_shop_php_service_shop-worker-1 php < /tmp/lock_proof.php
wait
# attendu : un OUI, un non. Deux OUI = les répliques ne partagent pas leurs verrous.
```

Les noms de conteneurs dépendent du nombre de répliques : `docker compose ps` les liste.

## Règles

- Jamais `flock` (ni `semaphore`, ni aucun store local) hors de `when@test`.
- Redis du service uniquement, base 1 — jamais celui de `service_identity`.
- Tout nouveau verrou porte un **TTL** : c'est le filet si le processus meurt en le tenant.
- Préfixer les clés par le contexte (`catalog.…`, `customer.…`) pour qu'elles restent lisibles dans
  `redis-cli -n 1 KEYS '*'`.
