FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/dltk
COPY app/ /var/www/dltk/app/
COPY deploy/php.ini /usr/local/etc/php/conf.d/dltk.ini
COPY deploy/php-fpm.conf /usr/local/etc/php-fpm.d/zz-dltk.conf
RUN mkdir -p /var/lib/php/sessions && chown www-data:www-data /var/lib/php/sessions
