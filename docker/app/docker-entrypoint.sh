#!/bin/sh
set -e

if [ "${1#-}" != "$1" ]; then
	set -- php-fpm "$@"
fi

# Meme mecanisme que le monolithe : var/ est bind-monte depuis l'hote, il doit
# rester ecrivable a la fois par www-data (php-fpm) et par l'utilisateur hote.
if [ "$1" = 'php-fpm' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
  cd /var/www
  host_uid="$(stat -c '%u' .)"

  mkdir -p var/cache var/log
  chown -R www-data:www-data var

  setfacl -R -m u:www-data:rwX -m u:"${host_uid}":rwX var
  setfacl -dR -m u:www-data:rwX -m u:"${host_uid}":rwX var
  find var -type d -exec chmod 0770 {} +
  find var -type f -exec chmod 0660 {} +
fi

exec docker-php-entrypoint "$@"
