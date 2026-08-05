#!/usr/bin/env bash
# Pulls the latest committed build from GitHub and applies it on the server.
# Run this over SSH from the app directory after `git push` from your machine:
#
#   cd ~/domains/cmvshipping.com/cmv_portal_app
#   ./deploy.sh
#
# No Node/build step here — frontend assets are pre-built and committed
# (see docs/DEPLOY_HOSTINGER.md). This only pulls code, installs PHP deps,
# runs pending migrations, and refreshes caches.

set -euo pipefail

BRANCH="${BRANCH:-phase1-build}"
# The server's default `php` on the CLI can lag behind what composer.json
# requires (see docs/DEPLOY_HOSTINGER.md — CloudLinux "Selector" gotcha).
# Override with: PHP_BIN=/path/to/php ./deploy.sh
PHP_BIN="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

if [ ! -x "$PHP_BIN" ]; then
    echo "PHP binary not found at $PHP_BIN — set PHP_BIN=... to the correct path (see ls -la /opt/alt/php*/usr/bin/php)." >&2
    exit 1
fi

echo "==> Pulling $BRANCH..."
git pull origin "$BRANCH"

echo "==> Installing PHP dependencies..."
"$PHP_BIN" "$(command -v composer)" install --no-dev --optimize-autoloader

echo "==> Running migrations..."
"$PHP_BIN" artisan migrate --force

echo "==> Refreshing caches..."
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

echo "==> Deploy complete."
