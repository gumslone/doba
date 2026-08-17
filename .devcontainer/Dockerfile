# Codespaces / dev container image for the Doba demo.
#
# PHP 8.4 is the floor, not a preference: SQLite's BEGIN IMMEDIATE — the
# thing that stops two guests booking the last room at once — only reaches
# the driver through the framework's PHP >= 8.4 path (§6).
FROM mcr.microsoft.com/devcontainers/php:1-8.4-bookworm

# install-php-extensions is idempotent and resolves the system libraries
# each extension needs, which is the part that makes a hand-rolled
# docker-php-ext-install line rot six months later.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        intl \
        gd \
        zip \
        pdo_sqlite \
        pdo_mysql \
        bcmath \
    && rm -rf /var/lib/apt/lists/*
