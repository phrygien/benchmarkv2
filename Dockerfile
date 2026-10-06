# ---------- Étape 1 : dépendances PHP ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-interaction --no-progress --no-scripts \
    --prefer-dist --optimize-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---------- Étape 2 : build des assets (Vite) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---------- Étape 3 : image finale ----------
FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
    nginx supervisor bash curl git unzip \
    libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    libxml2-dev oniguruma-dev icu-dev libpq-dev \
    $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
    pdo_mysql pdo_pgsql mbstring zip gd xml intl bcmath pcntl opcache exif \
 && git clone --depth 1 --branch 6.3.0 https://github.com/phpredis/phpredis.git /usr/src/php/ext/redis \
 && rm -rf /usr/src/php/ext/redis/.git \
 && docker-php-ext-install redis \
 && apk del $PHPIZE_DEPS \
 && rm -rf /var/cache/apk/* /tmp/pear

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

EXPOSE 443
ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
