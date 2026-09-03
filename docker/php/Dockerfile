# The only image we build. It bakes the PHP runtime and nothing else:
# all application code, templates and config are bind-mounted from the host.
FROM php:8.3-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpq-dev \
        poppler-utils; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
    a2enmod rewrite headers; \
    rm -rf /var/lib/apt/lists/*
