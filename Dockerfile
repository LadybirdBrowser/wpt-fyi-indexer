FROM php:8.4.16-cli-alpine3.22

LABEL maintainer="Ladybird Browser Initiative <contact@ladybird.org>"

COPY --from=composer:2.9.2 /usr/bin/composer /usr/local/bin/composer

COPY . /app

RUN apk add --no-cache git libpq-dev sudo \
    && docker-php-ext-install pgsql \
    && adduser -D -H indexer \
    && chown -R indexer:indexer /app \
    && sudo -u indexer sh -c 'cd /app && composer install --no-interaction' \ 
    && apk del git

USER indexer
WORKDIR /app

ENTRYPOINT ["/app/bin/console"]
CMD ["app:sync-totals"]
