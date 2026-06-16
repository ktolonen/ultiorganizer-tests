FROM php:8.3-apache

ENV DEBIAN_FRONTEND=noninteractive
ENV APACHE_DOCUMENT_ROOT=/workspace/.runtime/webroot

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        locales \
        mariadb-client \
        python3 \
        rsync \
        unzip \
        wget \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli gettext mbstring gd \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && a2enmod rewrite \
    && sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && locale-gen en_GB.UTF-8 fi_FI.UTF-8 \
    && update-locale \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php-test/workspace.conf /etc/apache2/conf-available/workspace.conf
COPY docker/php-test/disable-path-caches.ini /usr/local/etc/php/conf.d/zz-disable-path-caches.ini
COPY docker/php-test/pcov.ini /usr/local/etc/php/conf.d/zz-pcov.ini

RUN a2enconf workspace
