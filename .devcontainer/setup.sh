#!/usr/bin/env bash
#
# One-time bootstrap for a Codespace or local dev container.
#
# Idempotent throughout: onCreateCommand runs again when a Codespace is
# rebuilt, and a second run must not regenerate the app key (invalidating
# every encrypted value) or re-seed over work someone has done in the
# admin.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "→ PHP dependencies"
composer install --no-interaction --prefer-dist

echo "→ Front-end build"
npm ci
npm run build

if [ ! -f .env ]; then
    echo "→ .env"
    cp .env.example .env
    php artisan key:generate --force
fi

# Codespaces answers on a proxied HTTPS subdomain, not on localhost.
#
# URLs generated inside a request are fine either way — the app trusts the
# proxy — but everything generated OUTSIDE one reads APP_URL: the sitemap
# `doba:sitemap` writes to disk, the iCal URLs shown in the admin, the
# links in queued mail. Left at localhost, an SEO demo ships a sitemap
# advertising localhost, which is the opposite of the point.
if [ -n "${CODESPACE_NAME:-}" ]; then
    APP_URL="https://${CODESPACE_NAME}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"

    echo "→ APP_URL=${APP_URL}"
    sed -i "s#^APP_URL=.*#APP_URL=${APP_URL}#" .env

    # The Codespace answers on *.app.github.dev, a domain this install does
    # not own. HSTS with includeSubDomains there would assert a year of
    # policy over somebody else's wildcard host.
    if grep -q '^DOBA_HSTS=' .env; then
        sed -i "s#^DOBA_HSTS=.*#DOBA_HSTS=false#" .env
    else
        printf '\nDOBA_HSTS=false\n' >> .env
    fi
fi

echo "→ Database"
touch database/database.sqlite

# --force because a Codespace has no TTY to confirm at; --seed only on a
# fresh database, so a rebuild never wipes what the demo user changed.
if [ -s database/database.sqlite ]; then
    php artisan migrate --force
else
    php artisan migrate --seed --force
fi

php artisan storage:link || true

php artisan config:clear

cat <<'EOF'

  Doba is ready.

  The site opens on port 8000 as soon as the Codespace attaches.
  Admin: /admin  ·  admin@example.com / password

  Change those before pointing anything public at this install —
  DOBA_ADMIN_EMAIL and DOBA_ADMIN_PASSWORD are read by the seeder.

EOF
