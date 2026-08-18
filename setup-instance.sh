#!/usr/bin/env bash
# First-time install of a new instance (demo, or a new client) on Hostinger.
#
# deploy.sh updates an instance that already exists; this creates one. Run it
# over SSH, from anywhere:
#
#   APP_DIR=~/domains/harkcreation.com/shipaccdemo_app \
#   DOC_ROOT=~/domains/harkcreation.com/public_html/shippingaccountsdemo \
#   APP_URL=https://shippingaccountsdemo.harkcreation.com \
#   APP_NAME="Hark Creation Accounts" \
#   DB_NAME=u925208630_shipaccdemo DB_USER=u925208630_shipaccdemo \
#   ./setup-instance.sh
#
# The script prompts for the database password unless DB_PASSWORD is already
# exported, so the secret lands only in the instance's .env — never in this
# file, and never in shell history if you let it prompt.
#
# The app is installed OUTSIDE the document root on purpose. If the repo were
# cloned straight into the subdomain folder, .env, storage/ and vendor/ would
# all be fetchable over HTTP.

set -euo pipefail

REPO="${REPO:-https://github.com/ahmedrashid6666/cmv_portal.git}"
BRANCH="${BRANCH:-phase1-build}"

: "${APP_DIR:?Set APP_DIR — where the application lives (must be outside the document root)}"
: "${DOC_ROOT:?Set DOC_ROOT — the subdomain folder the web server serves}"
: "${APP_URL:?Set APP_URL — e.g. https://shippingaccountsdemo.harkcreation.com}"
: "${DB_NAME:?Set DB_NAME}"
: "${DB_USER:?Set DB_USER}"
APP_NAME="${APP_NAME:-Hark Creation Accounts}"
DB_HOST="${DB_HOST:-localhost}"

# The default `php` on CloudLinux often lags what composer.json requires.
if [ -n "${PHP_BIN:-}" ]; then
    :
elif [ -x /opt/alt/php83/usr/bin/php ]; then
    PHP_BIN=/opt/alt/php83/usr/bin/php
else
    PHP_BIN="$(command -v php)"
fi
[ -x "$PHP_BIN" ] || { echo "No usable PHP binary. Set PHP_BIN=... (try: ls -d /opt/alt/php*/usr/bin/php)" >&2; exit 1; }
echo "==> PHP: $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"

case "$(readlink -f "$APP_DIR")/" in
    "$(readlink -f "$DOC_ROOT")"/*)
        echo "Refusing to install: APP_DIR is inside DOC_ROOT, which would expose .env over HTTP." >&2
        exit 1
        ;;
esac

# ---------------------------------------------------------------- code
if [ -d "$APP_DIR/.git" ]; then
    echo "==> Updating existing checkout at $APP_DIR"
    git -C "$APP_DIR" fetch origin "$BRANCH"
    git -C "$APP_DIR" checkout "$BRANCH"
    git -C "$APP_DIR" pull origin "$BRANCH"
else
    echo "==> Cloning $BRANCH into $APP_DIR"
    mkdir -p "$(dirname "$APP_DIR")"
    git clone -b "$BRANCH" "$REPO" "$APP_DIR"
fi

cd "$APP_DIR"

# vendor/ and public/build/ are committed, so this is a no-op refresh when
# Composer is unavailable on the host.
if command -v composer >/dev/null 2>&1; then
    echo "==> Refreshing PHP dependencies"
    "$PHP_BIN" "$(command -v composer)" install --no-dev --optimize-autoloader
fi

# ---------------------------------------------------------------- .env
if [ -f .env ]; then
    echo "==> .env already exists — leaving it untouched"
else
    echo "==> Creating .env"
    if [ -z "${DB_PASSWORD:-}" ]; then
        printf 'Database password for %s: ' "$DB_USER" >&2
        read -rs DB_PASSWORD
        echo >&2
    fi

    cat > .env <<ENV
APP_NAME="${APP_NAME}"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${APP_URL}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=${DB_HOST}
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD="${DB_PASSWORD}"

SESSION_DRIVER=database
SESSION_LIFETIME=120
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@harkcreation.com"
MAIL_FROM_NAME="\${APP_NAME}"
ENV
    unset DB_PASSWORD
    chmod 600 .env

    "$PHP_BIN" artisan key:generate --force
fi

# ---------------------------------------------------------------- database
echo "==> Running migrations"
"$PHP_BIN" artisan migrate --force

echo "==> Seeding defaults"
"$PHP_BIN" artisan db:seed --class=DefaultDataSeeder --force

"$PHP_BIN" artisan storage:link || true

# ---------------------------------------------------------------- document root
# The panel fixes the subdomain folder as the document root, so it has to end up
# serving the app's public/ directory.
#
# A symlink is strongly preferred: it costs nothing on future deploys, because
# new build assets appear the moment git pulls them. Copying works too, but then
# every deploy has to re-sync the copy, which is easy to forget.
echo "==> Wiring $DOC_ROOT -> $APP_DIR/public"

# A brand-new subdomain ships with a placeholder page. Apache's DirectoryIndex
# prefers index.html over index.php, so leaving one behind means the placeholder
# is served instead of the app. These are the only names we will clear out.
PLACEHOLDERS='index.html index.htm default.html default.htm index.php.bak .well-known'

docroot_has_only_placeholders() {
    local entry
    for entry in "$DOC_ROOT"/* "$DOC_ROOT"/.[!.]*; do
        [ -e "$entry" ] || continue
        case " $PLACEHOLDERS " in
            *" $(basename "$entry") "*) ;;
            *) return 1 ;;
        esac
    done
    return 0
}

if [ -L "$DOC_ROOT" ]; then
    ln -sfn "$APP_DIR/public" "$DOC_ROOT"
    DOCROOT_MODE=symlink
elif [ ! -e "$DOC_ROOT" ]; then
    ln -s "$APP_DIR/public" "$DOC_ROOT"
    DOCROOT_MODE=symlink
elif [ -d "$DOC_ROOT" ] && docroot_has_only_placeholders; then
    echo "    (clearing placeholder page, then linking)"
    rm -rf "${DOC_ROOT:?}"/* "${DOC_ROOT:?}"/.[!.]* 2>/dev/null || true
    if rmdir "$DOC_ROOT" 2>/dev/null && ln -s "$APP_DIR/public" "$DOC_ROOT" 2>/dev/null; then
        DOCROOT_MODE=symlink
    else
        # Panel owns the directory and will not let it be replaced.
        mkdir -p "$DOC_ROOT"
        cp -R "$APP_DIR/public/." "$DOC_ROOT/"
        DOCROOT_MODE=copy
    fi
else
    echo "    (directory holds files I will not touch — copying instead of linking)"
    cp -R "$APP_DIR/public/." "$DOC_ROOT/"
    DOCROOT_MODE=copy
fi

if [ "$DOCROOT_MODE" = copy ]; then
    # Record the path so deploy.sh can refresh this copy on every future pull.
    printf '%s\n' "$DOC_ROOT" > "$APP_DIR/.docroot"
    "$APP_DIR/sync-docroot.sh" "$DOC_ROOT"
else
    rm -f "$APP_DIR/.docroot"
fi

# ---------------------------------------------------------------- caches
echo "==> Warming caches"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

echo
echo "==> Done. $APP_URL should now serve the app."
echo "    Log in, then change the seeded admin password immediately."
