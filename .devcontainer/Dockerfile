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

# The base image ships a yarn apt source whose signing key it no longer
# carries, so every apt-get update inside it fails — including the one
# install-php-extensions runs. Nothing here uses yarn (the build is npm),
# so the stale source goes rather than being patched back to life.
RUN rm -f /etc/apt/sources.list.d/yarn.list

RUN install-php-extensions \
        intl \
        gd \
        zip \
        pdo_sqlite \
        pdo_mysql \
        bcmath \
    && rm -rf /var/lib/apt/lists/*

# The base image ships Xdebug in step-debug mode, so every PHP process
# stalls looking for a debugger that is not there and prints a warning
# when it gives up. Off by default (devcontainer.json sets XDEBUG_MODE);
# a developer who wants it flips that one variable.
ENV XDEBUG_MODE=off
