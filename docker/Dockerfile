# PHP 8.3.36 + swoole 6.2.3 (stable). The previous base,
# phpswoole/swoole:5.1-php8.3-alpine, froze at PHP 8.3.26 (2025-10-27) and
# never received the CVE-2026-109618/109619 security fix — the maintainer's
# 5.1 line is abandoned in favour of 6.x. Pinned to a stable swoole rather
# than the floating `php8.3` tag, which tracks release candidates
# (6.3.0RC1 as of 2026-10-22). composer allows ext-swoole "^5.1 || ^6.0";
# the unit suite passes on 6.2.3 / PHP 8.3.36 (verified 2026-10-22).
FROM phpswoole/swoole:6.2.3-php8.3 AS base

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip curl bash \
 && rm -rf /var/lib/apt/lists/*

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && chown -R 1000:1000 /app

USER 1000:1000

ENTRYPOINT ["/app/bin/vested-connect"]
CMD ["worker", "--bootstrap=/app/bootstrap.php"]
