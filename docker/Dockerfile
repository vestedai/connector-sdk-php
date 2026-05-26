FROM phpswoole/swoole:5.1-php8.3-alpine AS base

RUN apk add --no-cache git unzip bash \
 && rm -rf /var/cache/apk/*

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && chown -R 1000:1000 /app

USER 1000:1000

ENTRYPOINT ["/app/bin/vested-connect"]
CMD ["worker", "--bootstrap=/app/bootstrap.php"]
