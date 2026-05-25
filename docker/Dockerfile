FROM php:8.3-cli-alpine AS base

RUN apk add --no-cache git unzip bash autoconf gcc g++ make linux-headers zlib-dev \
 && pecl install grpc protobuf \
 && docker-php-ext-enable grpc protobuf \
 && docker-php-ext-install pcntl sockets bcmath \
 && apk del autoconf gcc g++ make linux-headers zlib-dev \
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
