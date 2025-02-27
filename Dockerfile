FROM php:8.4.4-cli-alpine3.21

LABEL maintainer="Ladybird Browser Initiative <contact@ladybird.org>"

COPY --from=composer:2.2.25 /usr/bin/composer /usr/local/bin/composer

COPY . /app

RUN apk add --no-cache libpq-dev \
    && docker-php-ext-install pgsql \
    && adduser -D -H indexer \
    && chown -R indexer:indexer /app

USER indexer
WORKDIR /app

RUN composer install --no-interaction

ENTRYPOINT ["/app/bin/console"]
CMD ["app:sync-totals"]
