FROM php:8.4-fpm

ENV TZ=Europe/Paris
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Volontairement minimal : ce service n'a ni base de donnees, ni broker, ni cache
# distribue. Pas de pdo_pgsql, pas d'amqp, pas de redis, pas de supervisor.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git curl unzip nano acl \
        libzip-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install zip opcache intl \
    && docker-php-ext-enable zip opcache intl

COPY docker/app/php-fpm/php.ini /usr/local/etc/php/php.ini
COPY --from=composer:2.9.2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .
RUN git config --global --add safe.directory /var/www

COPY docker/app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]

CMD ["php-fpm"]
