#!/bin/sh
set -e

if [ "${1#-}" != "$1" ]; then
	set -- php-fpm "$@"
fi

# En developpement, ces repertoires sont bind-montes depuis l'hote. Ils doivent
# rester ecrivable a la fois par www-data (php-fpm / worker) et par l'utilisateur
# hote. Le processus principal est supervisord : il doit donc aussi declencher
# cette preparation, pas seulement les invocations directes de PHP.
if [ "$1" = '/usr/bin/supervisord' ] || [ "$1" = 'php-fpm' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
  cd /var/www
  host_uid="$(stat -c '%u' .)"

  mkdir -p var/cache var/log
  chown -R www-data:www-data var

  setfacl -R -m u:www-data:rwX -m u:"${host_uid}":rwX var
  setfacl -dR -m u:www-data:rwX -m u:"${host_uid}":rwX var
  find var -type d -exec chmod 0770 {} +
  find var -type f -exec chmod 0660 {} +

  upload_directory='public/uploads/images/catalog/product'
  mkdir -p "$upload_directory"
  setfacl -R -m u:www-data:rwX -m u:"${host_uid}":rwX "$upload_directory"
  setfacl -dR -m u:www-data:rwX -m u:"${host_uid}":rwX "$upload_directory"
fi

exec docker-php-entrypoint "$@"
