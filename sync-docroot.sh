#!/usr/bin/env bash
# Refresh a copied document root from the app's public/ directory.
#
# Only needed where the subdomain folder could not be replaced with a symlink to
# public/ (see setup-instance.sh). In that case setup-instance.sh records the
# path in .docroot, and deploy.sh calls this after every pull — otherwise the
# app would update while the browser kept being served the previous build's
# assets, whose hashed filenames no longer match the manifest.
#
#   ./sync-docroot.sh [DOC_ROOT]
#
# Reads .docroot when DOC_ROOT is not given. Exits quietly when there is nothing
# to sync, so deploy.sh can call it unconditionally.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOC_ROOT="${1:-}"

if [ -z "$DOC_ROOT" ] && [ -f "$APP_DIR/.docroot" ]; then
    DOC_ROOT="$(cat "$APP_DIR/.docroot")"
fi

# Symlinked document roots need no sync — that is the whole point of them.
[ -n "$DOC_ROOT" ] || exit 0
[ -L "$DOC_ROOT" ] && exit 0
[ -d "$DOC_ROOT" ] || { echo "sync-docroot: $DOC_ROOT is not a directory" >&2; exit 1; }

echo "==> Syncing $DOC_ROOT from public/"

# Copy assets across. public/index.php is deliberately overwritten afterwards:
# it resolves the app via __DIR__/.., which is wrong from the document root.
cp -R "$APP_DIR/public/." "$DOC_ROOT/"

cat > "$DOC_ROOT/index.php" <<'PHPFRONT'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// The application lives outside this document root — see setup-instance.sh.
$base = require __DIR__.'/app-path.php';

if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $base.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
PHPFRONT

printf "<?php return '%s';\n" "$APP_DIR" > "$DOC_ROOT/app-path.php"

# Apache's DirectoryIndex prefers index.html, which would shadow the app.
rm -f "$DOC_ROOT/index.html" "$DOC_ROOT/index.htm" \
      "$DOC_ROOT/default.html" "$DOC_ROOT/default.htm"

# Assets are content-hashed, so old builds pile up. Drop any that the current
# manifest no longer references.
MANIFEST="$APP_DIR/public/build/manifest.json"
if [ -f "$MANIFEST" ] && [ -d "$DOC_ROOT/build/assets" ]; then
    find "$DOC_ROOT/build/assets" -type f | while read -r stale; do
        [ -e "$APP_DIR/public/build/assets/$(basename "$stale")" ] || rm -f "$stale"
    done
fi

echo "    synced."
