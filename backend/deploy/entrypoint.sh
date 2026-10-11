#!/bin/sh
# Runs every time the container starts: check the settings, get the database ready, then start everything.
set -eu
cd /var/www/html

fail() {
    echo "STARTUP STOPPED: $1" >&2
    exit 1
}

[ -n "${APP_KEY:-}" ] || fail "APP_KEY is not set. Make one with: php artisan key:generate --show"
[ -n "${DB_URL:-}${DB_HOST:-}" ] || fail "No database is configured. Set DB_URL to your database's connection string."

# The host decides the port; nginx has to listen on it.
export PORT="${PORT:-8080}"
sed "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# The payment simulator delivers its webhooks to this same container: use its own port, not a trip out through the internet and back.
export PAYMENT_FAKE_WEBHOOK_URL="${PAYMENT_FAKE_WEBHOOK_URL:-http://127.0.0.1:${PORT}/api/webhooks/fake}"

php artisan storage:link --force > /dev/null 2>&1 || true

# Read the settings once and remember them (faster). Routes are deliberately not cached: two of them are small closures.
php artisan config:cache
php artisan event:cache
php artisan view:cache

# A free database may be asleep and take a few seconds to wake up, so try a few times before giving up. A setting that was
# REFUSED (exit code 2, e.g. tables that would be published on Supabase) is not retried: waiting will not fix it.
attempt=1
while true; do
    prepared=0
    php artisan shop:prepare-database --no-interaction || prepared=$?
    [ "$prepared" -ne 2 ] || fail "the database settings were refused (see the message above)."

    if [ "$prepared" -eq 0 ] && php artisan migrate --force --no-interaction; then
        break
    fi

    [ "$attempt" -lt 8 ] || fail "the database could not be reached or updated (see the message above)."
    echo "Database not ready (try $attempt of 8). Waiting..." >&2
    attempt=$((attempt + 1))
    sleep 5
done

# Roles, rules, the first admin and (only if asked, and only into an empty shop) the demo catalog. Safe to repeat.
php artisan shop:install --no-interaction

chown -R www-data:www-data storage bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisord.conf
