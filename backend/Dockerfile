FROM php:8.2-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql bcmath intl zip opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Production PHP settings: compiled code stays in memory (opcache) and is not re-checked on every request.
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=192'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'realpath_cache_size=4096K'; \
    echo 'realpath_cache_ttl=600'; \
    echo 'memory_limit=256M'; \
    echo 'upload_max_filesize=25M'; \
    echo 'post_max_size=26M'; \
  } > /usr/local/etc/php/conf.d/tuitionpro.ini \
  && { \
    echo '[www]'; \
    echo 'pm = dynamic'; \
    echo 'pm.max_children = 20'; \
    echo 'pm.start_servers = 4'; \
    echo 'pm.min_spare_servers = 2'; \
    echo 'pm.max_spare_servers = 8'; \
    echo 'pm.max_requests = 1000'; \
  } > /usr/local/etc/php-fpm.d/zz-tuitionpro.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan event:cache && php-fpm"]
