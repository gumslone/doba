#!/usr/bin/env bash
#
# Doba installer — the mile before the wizard.
#
#   curl -fsSL https://raw.githubusercontent.com/gumslone/doba/main/scripts/install.sh | bash
#
# Downloads the newest release, verifies its checksum, extracts it, writes
# a bootable .env and hands you the URL of the install wizard — which is
# where all actual configuration happens. This script configures nothing
# it does not have to: two implementations of "set up the hotel" would
# eventually differ in exactly the step that matters.
#
#   DOBA_DIR=/var/www/hotel   where to install        (default: ./doba)
#   DOBA_URL=https://...      the site's public URL   (default: asked, or http://localhost)
#   DOBA_TARBALL=/path.tar.gz install from a local file instead of GitHub
#                             (used by CI to prove this script installs the
#                             tarball it just built)

set -euo pipefail

REPO="gumslone/doba"
DIR="${DOBA_DIR:-doba}"

say()  { printf '\033[1m%s\033[0m\n' "$*"; }
fail() { printf '\033[31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- checks
command -v php >/dev/null 2>&1 || fail "php was not found on PATH. Doba needs PHP 8.4 or newer."
command -v tar >/dev/null 2>&1 || fail "tar was not found on PATH."

if command -v curl >/dev/null 2>&1; then
  fetch() { curl -fsSL "$1"; }
  fetch_to() { curl -fsSL -o "$2" "$1"; }
elif command -v wget >/dev/null 2>&1; then
  fetch() { wget -qO- "$1"; }
  fetch_to() { wget -qO "$2" "$1"; }
else
  fail "Neither curl nor wget was found."
fi

# The full check (extensions, versions, writability) belongs to the
# wizard, which shows each failure with its fix. Here only what would
# make the next thirty seconds fail confusingly.
php -r 'exit(version_compare(PHP_VERSION, "8.4", ">=") ? 0 : 1);' \
  || fail "This machine runs PHP $(php -r 'echo PHP_VERSION;'), and Doba needs 8.4 or newer."

[ -e "$DIR" ] && [ -n "$(ls -A "$DIR" 2>/dev/null)" ] \
  && fail "$DIR already exists and is not empty. To update an existing install, use Admin → Update or 'php artisan doba:update'."

# ---------------------------------------------------------------- fetch
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [ -n "${DOBA_TARBALL:-}" ]; then
  say "Installing from ${DOBA_TARBALL}"
  cp "$DOBA_TARBALL" "$WORK/doba.tar.gz"
  [ -f "${DOBA_TARBALL}.sha256" ] && sed 's/ .*/  doba.tar.gz/' "${DOBA_TARBALL}.sha256" > "$WORK/doba.tar.gz.sha256"
else
  # The releases LIST, not /releases/latest: that endpoint only ever
  # returns full releases, and every 0.x Doba release is marked
  # pre-release on purpose — asking it would 404 until v1.0.0.
  say "Finding the newest release of ${REPO}…"
  ASSETS="$(fetch "https://api.github.com/repos/${REPO}/releases?per_page=1" \
    | php -r '$r = json_decode(stream_get_contents(STDIN), true);
              foreach (($r[0]["assets"] ?? []) as $a) echo $a["browser_download_url"], "\n";')"

  TAR_URL="$(printf '%s\n' "$ASSETS" | grep '\.tar\.gz$' | head -1 || true)"
  SUM_URL="$(printf '%s\n' "$ASSETS" | grep '\.sha256$'  | head -1 || true)"

  [ -n "$TAR_URL" ] || fail "No release tarball found at github.com/${REPO}/releases."

  say "Downloading $(basename "$TAR_URL")…"
  fetch_to "$TAR_URL" "$WORK/doba.tar.gz"
  [ -n "$SUM_URL" ] && fetch_to "$SUM_URL" "$WORK/raw.sha256" \
    && sed 's/ .*/  doba.tar.gz/' "$WORK/raw.sha256" > "$WORK/doba.tar.gz.sha256"
fi

if [ -f "$WORK/doba.tar.gz.sha256" ]; then
  # sha256sum on Linux, shasum on macOS — one of the two exists everywhere.
  ( cd "$WORK" && { sha256sum -c doba.tar.gz.sha256 >/dev/null 2>&1 \
                    || shasum -a 256 -c doba.tar.gz.sha256 >/dev/null; } ) \
    || fail "Checksum mismatch — the download is corrupt or tampered with. Nothing was installed."
  say "Checksum verified."
fi

# ---------------------------------------------------------------- extract
tar -xzf "$WORK/doba.tar.gz" -C "$WORK"
[ -d "$WORK/doba" ] || fail "The tarball did not contain a doba/ directory."
mkdir -p "$(dirname "$DIR")"
mv "$WORK/doba" "$DIR"

# ---------------------------------------------------------------- .env
cd "$DIR"

URL="${DOBA_URL:-}"
if [ -z "$URL" ] && [ -t 0 ]; then
  printf 'Public URL of the site [http://localhost]: '
  read -r URL || true
fi
URL="${URL:-http://localhost}"

# env.example in a release, .env.example in a checkout.
SRC=".env.example"; [ -f env.example ] && SRC="env.example"
cp "$SRC" .env

# Production defaults, not the example's development ones: a hotel must
# not boot with APP_DEBUG=true, because debug pages print configuration
# to whoever causes an error.
php -r '
    $env = file_get_contents(".env");
    $set = function (string $k, string $v) use (&$env): void {
        $env = preg_match("/^{$k}=.*$/m", $env)
            ? preg_replace("/^{$k}=.*$/m", "{$k}={$v}", $env)
            : $env . "\n{$k}={$v}\n";
    };
    $set("APP_ENV", "production");
    $set("APP_DEBUG", "false");
    $set("APP_KEY", "base64:" . base64_encode(random_bytes(32)));
    $set("APP_URL", $argv[1]);
    file_put_contents(".env", $env);
' "$URL"

say ""
say "Doba is extracted into $(pwd)."
say ""
say "Two steps remain, both in a browser:"
say "  1. Point your web server's document root at $(pwd)/public"
say "     (for a quick local look instead: php artisan serve)"
say "  2. Open ${URL} — the install wizard takes it from there."
