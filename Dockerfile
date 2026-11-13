# Image backend untuk tiga service di deploy/compose.yaml: app (FrankenPHP melayani HTTP dan PHP dalam satu proses),
# queue, dan scheduler. FrankenPHP dipilih supaya tidak perlu Nginx kedua di dalam container; Nginx di host tetap
# memegang TLS. .env tidak masuk image; diberikan compose lewat env_file.
FROM dunglas/frankenphp:1-php8.5-bookworm AS dasar

# Ekstensi sama dengan instalasi VPS sekarang (pdo_mysql, mbstring, xml, curl, zip, gd, bcmath, intl).
RUN install-php-extensions pdo_mysql gd zip bcmath intl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini $PHP_INI_DIR/conf.d/99-tkta8.ini

WORKDIR /app

FROM dasar AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM dasar
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY . .
# Autoloader dibuat setelah kode disalin; package:discover butuh artisan.
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && rm /usr/bin/composer \
    && mkdir -p storage/app/public storage/app/private \
    && ln -sfn ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache /data /config

COPY docker/entrypoint.sh /usr/local/bin/entrypoint-tkta8
ENV SERVER_NAME=:8080
USER www-data
EXPOSE 8080
ENTRYPOINT ["entrypoint-tkta8"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
