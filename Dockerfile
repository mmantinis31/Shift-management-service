FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libfcgi-bin libicu72 libonig5 libxml2 libicu-dev libonig-dev libxml2-dev libsqlite3-dev unzip \
    && docker-php-ext-install -j"$(nproc)" intl mbstring pdo_sqlite dom xml xmlwriter opcache \
    && apt-get purge -y --auto-remove libicu-dev libonig-dev libxml2-dev libsqlite3-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && mkdir -p /app/var /app/vendor /tmp/composer \
    && chown -R www-data:www-data /app /tmp/composer

COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-application.conf
WORKDIR /app
USER www-data
EXPOSE 9000

ENV COMPOSER_HOME=/tmp/composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/development.ini /usr/local/etc/php/conf.d/zz-application.ini
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-interaction
