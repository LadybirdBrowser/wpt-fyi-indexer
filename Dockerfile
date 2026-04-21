FROM php:8.5.5-cli-alpine3.22

LABEL maintainer="Ladybird Browser Initiative <contact@ladybird.org>"

COPY --from=composer:2.9.7 /usr/bin/composer /usr/local/bin/composer

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'memory_limit = 512M' > "$PHP_INI_DIR/conf.d/overrides.ini"

RUN apk add --no-cache git libpq-dev sudo \
    && docker-php-ext-install pgsql \
    && adduser -D -H indexer

COPY . /app

RUN chown -R indexer:indexer /app \
    && sudo -u indexer sh -c 'cd /app && composer install --no-dev --no-interaction' \
    && apk del git

USER indexer
WORKDIR /app

ENTRYPOINT ["/app/bin/console"]
CMD ["app:sync-totals"]
