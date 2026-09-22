# The production image (§15): one container, one volume, the same wizard.
#
#   docker run -p 8080:80 -v doba-data:/data -e DOBA_URL=http://localhost:8080 ghcr.io/gumslone/doba
#
# Everything a hotel owns lives under /data — .env, storage/ (photos,
# invoices, backups, logs) and the SQLite file — so replacing the container
# with a newer image is the whole update: the entrypoint migrates on boot
# through the same health-checked updater a tarball install uses.

# ---- PHP base: the extensions, once, for both the build and the runtime ----
FROM php:8.4-apache-bookworm AS base

# PHP 8.4 is the floor, not a preference: SQLite's BEGIN IMMEDIATE — what
# stops two guests booking the last room at once — needs it (§6).
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl gd zip pdo_sqlite pdo_mysql bcmath opcache \
    && rm -rf /var/lib/apt/lists/*

# ---- Composer: production dependencies only ----
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip git && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---- Front-end build ----
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Tailwind scans a vendor Blade file for the pagination classes.
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- Runtime ----
FROM base AS runtime

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    DOBA_DATA=/data \
    DOBA_SQLITE_PATH=/data/database.sqlite

RUN a2enmod rewrite headers expires deflate \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\nServerName doba\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/doba.conf \
    && a2enconf doba \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini $PHP_INI_DIR/conf.d/doba.ini

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# storage/ becomes a link into the volume; the skeleton it shipped with is
# kept beside it so the entrypoint can lay it down on first boot — and top
# it up when a later release adds a directory.
#
# The base image leaves /var/www/html sticky and world-writable. Under the
# kernel's protected_symlinks that forbids www-data from following a link
# root created there — so the wizard would report .env and storage/ as
# "not writable" while both are. The directory goes back to 755 and the
# links belong to the user that follows them.
RUN mv storage storage.dist \
    && ln -s /data/storage storage \
    && ln -s /data/.env .env \
    && ln -sfn ../storage/app/public public/storage \
    && mkdir -p /data \
    && chmod 755 /var/www/html \
    && chown -h www-data:www-data storage .env public/storage \
    && chown -R www-data:www-data /data bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/doba-entrypoint
RUN chmod +x /usr/local/bin/doba-entrypoint

VOLUME /data
EXPOSE 80

# Shallow: the deep check makes an HTTP request back to the site, and a
# container that is still starting would fail its own health check.
HEALTHCHECK --interval=60s --timeout=10s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/up") === false ? 1 : 0);'

ENTRYPOINT ["doba-entrypoint"]
CMD ["apache2-foreground"]
