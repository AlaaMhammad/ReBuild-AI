# Development / CI image for the Laravel API (PHP 8.2 CLI with PostgreSQL and Redis drivers).
FROM php:8.2-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        bcmath \
        pcntl \
        opcache \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo 'memory_limit=512M'; \
        echo 'opcache.enable_cli=0'; \
        echo 'date.timezone=UTC'; \
    } > /usr/local/etc/php/conf.d/rebuild.ini

WORKDIR /var/www/html
