# Architecture Docker Compose

`docker-compose.yaml` décrit le workload portable de `service_shop`. Il ne contient que les
processus nécessaires en développement comme en production. Le poste local est complété par
`docker-compose.override.yaml`, chargé automatiquement par Docker Compose.

## Topologie

```text
production : gateway Kong → service-shop (edge) → varnish → shop-nginx → app (PHP-FPM)

developpement : gateway Kong / localhost:20910 → service-shop (edge) → nginx → app (PHP-FPM)
                              |
                              +── mongodb (replica set rs0)
                              +── redis

worker ───────────────────────┘
```

En production, Varnish est raccordé au réseau externe `en_shop_php_edge` sous l'alias public
`service-shop`, consommé par Kong. En développement, l'override y raccorde directement nginx avec
ce même alias, afin que le profiler Symfony reflète chaque requête. MongoDB, Redis et le service qui
n'est pas exposé restent sur le réseau Compose `default` : la passerelle et les autres bounded
contexts ne peuvent pas joindre les données.

Varnish rejoint à la fois `edge` et `default`. Il vise donc nginx sous l'alias privé unique
`shop-nginx`, et jamais sous le nom générique `nginx`, ambigu sur `edge` avec `service_identity`.

## Services

| Service | Rôle | Démarrage |
|---|---|---|
| `mongodb` | catalogue et outbox MongoDB | singleton, volume `mongodb_data`, replica set `rs0` |
| `redis` | cache applicatif de queries partagé | singleton dédié à Shop |
| `app` | PHP-FPM et requêtes HTTP | attend MongoDB et Redis sains |
| `worker` | cron et consommateurs de l’outbox | même image que `app`, attend les mêmes dépendances |
| `nginx` | proxy FastCGI, backend privé de Varnish | attend `app` |
| `varnish` | cache HTTP public et réception des BAN | attend nginx |

MongoDB a une limite de 64 000 descripteurs : sans elle, `mongod` peut échouer avec
`TooManyFilesOpen`. Son healthcheck initialise `rs0` à la première exécution, puis vérifie son état
aux suivantes. Le replica set est obligatoire : les transactions MongoDB couvrent le flush ODM de
l’agrégat et de l’outbox.

## Une image, deux rôles

`app` et `worker` réemploient l’ancre de build et l’image
`en_shop_php_service_shop_app:latest`. `SUPERVISOR_ROLE` sélectionne les processus :

- `web` démarre PHP-FPM ;
- `worker` démarre cron et les deux consommateurs `domain_events`.

Les deux rôles peuvent ainsi évoluer indépendamment sans exécuter des versions différentes du code.
`app` n’a pas de `container_name`, ce qui autorise `make up APP_REPLICAS=…`.

## Varnish et invalidation

Les GET publics du catalogue passent par Varnish ; les requêtes avec `Authorization` ou `Cookie`
sont transmises sans être stockées. Après le commit MongoDB, le middleware de commandes transforme
les Domain Events du catalogue en `Cache-Tags` API Platform et envoie un BAN à `VARNISH_URL`.

La documentation détaillée du cache HTTP et de ses tags est dans
[`varnish_cache.md`](varnish_cache.md).

## Base et override

Le fichier de base ne publie aucun port, ne monte pas le code de l’hôte et construit la cible Docker
`prod`. L’override local ajoute le port nginx (`NGINX_EXPOSED_PORT`, 20910 par défaut), les bind
mounts, la cible `dev` et le réseau `edge`. `docker-compose.prod.yaml` raccorde au contraire Varnish
à `edge`; `make up-prod` combine ce fichier avec la base. Une production n’emporte donc ni Composer,
ni Xdebug, ni une dépendance au système de fichiers du développeur.
