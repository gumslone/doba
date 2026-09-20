#!/bin/sh
# Boot a Doba container (§15).
#
# First boot lays the storage skeleton and a bootable .env into /data and
# leaves the rest to the install wizard, exactly like the other
# installers. Every later boot — including the first one on a newer image
# — runs the health-checked updater, then starts the scheduler and the
# queue worker beside Apache, because a container has no crontab.
set -e

APP=/var/www/html
DATA="${DOBA_DATA:-/data}"
cd "$APP"

as_app() { su -s /bin/sh www-data -c "$1"; }

mkdir -p "$DATA/storage"

# Lay down the skeleton; -n never overwrites, so this also tops up a
# volume from an older release that lacks a directory this one needs.
cp -an "$APP/storage.dist/." "$DATA/storage/"

if [ ! -f "$DATA/.env" ]; then
    echo "doba: first boot — writing $DATA/.env"
    cp "$APP/.env.example" "$DATA/.env"
    URL="${DOBA_URL:-http://localhost:8080}"
    php -r '
        $path = $argv[1]; $env = file_get_contents($path);
        $set = function (string $k, string $v) use (&$env): void {
            $env = preg_match("/^{$k}=.*$/m", $env)
                ? preg_replace("/^{$k}=.*$/m", "{$k}={$v}", $env)
                : $env . "\n{$k}={$v}\n";
        };
        $set("APP_ENV", "production");
        $set("APP_DEBUG", "false");
        $set("APP_KEY", "base64:" . base64_encode(random_bytes(32)));
        $set("APP_URL", $argv[2]);
        // The wizard offers this path when SQLite is chosen; until then it
        // keeps artisan from pointing at a file inside the image.
        $set("DB_DATABASE", getenv("DOBA_SQLITE_PATH") ?: "/data/database.sqlite");
        if (str_starts_with($argv[2], "https://")) { $set("SESSION_SECURE_COOKIE", "true"); }
        file_put_contents($path, $env);
    ' "$DATA/.env" "$URL"
    chmod 600 "$DATA/.env"
elif [ -n "$DOBA_URL" ]; then
    # The address is the one thing that legitimately changes from outside:
    # a new domain, http becoming https behind a proxy.
    php -r '
        $path = $argv[1]; $env = file_get_contents($path);
        $env = preg_replace("/^APP_URL=.*$/m", "APP_URL=" . $argv[2], $env);
        file_put_contents($path, $env);
    ' "$DATA/.env" "$DOBA_URL"
fi

chown -R www-data:www-data "$DATA" "$APP/bootstrap/cache"

# Caches from the previous image describe the previous code.
rm -f "$APP"/bootstrap/cache/*.php

if [ -f "$DATA/storage/installed.lock" ]; then
    echo "doba: installed — running the updater"
    # Not fatal: a failed update restores its own snapshot and says why,
    # and a hotel is better served by the site it had than by a container
    # that refuses to start.
    as_app "php artisan doba:update" || echo "doba: update did not complete — see the output above and Admin → Update"
else
    echo "doba: not installed yet — open ${DOBA_URL:-http://localhost:8080} and follow the wizard."
    echo "doba: it will ask for the install token; read it with:"
    echo "doba:   docker exec <container> cat /data/storage/install-token.txt"
fi

if [ "$1" = "apache2-foreground" ]; then
    # The scheduler: holds expire, mail goes out, backups run.
    ( while true; do as_app "php artisan schedule:run" >/dev/null 2>&1 || true; sleep 60; done ) &
    # The queue: confirmation mail and webhooks. Restarted hourly so a
    # worker never outlives the code it loaded by much.
    ( while true; do as_app "php artisan queue:work --sleep=3 --tries=3 --max-time=3600" >/dev/null 2>&1 || true; sleep 5; done ) &
fi

exec "$@"
