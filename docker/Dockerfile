FROM php:8.3-cli-bookworm AS base

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions grpc protobuf pcntl sockets bcmath \
 && rm -rf /var/lib/apt/lists/*

# Composer (PHAR — works on any PHP runtime)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && chown -R 1000:1000 /app

USER 1000:1000

ENTRYPOINT ["/app/bin/vested-connect"]
CMD ["worker", "--bootstrap=/app/bootstrap.php"]
