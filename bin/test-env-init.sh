#!/usr/bin/env bash
# Provisions the throwaway WordPress test site used by `make test` and
# `make test-integration`. Runs against the newest WordPress (the
# `wordpress:latest` image), never against a remote site.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ -f .env ]; then
  set -a
  . ./.env
  set +a
fi

COMPOSE="docker compose -f docker-compose.test.yml"
WP_CLI="$COMPOSE run --rm -T wpcli wp"

WP_TEST_PORT=${WP_TEST_PORT:-8101}
SITE_URL="http://localhost:${WP_TEST_PORT}"
ADMIN_USER=${WP_ADMIN_USER:-admin}
ADMIN_PASS=${WP_ADMIN_PASSWORD:-admin}
ADMIN_EMAIL=${WP_ADMIN_EMAIL:-admin@example.com}

# Fixed, non-secret token: this site is disposable and never public.
KOFI_TEST_TOKEN=${KOFI_TEST_TOKEN:-test-verification-token}

echo ">>> Waiting for WordPress core files to be extracted..."
attempt=0
until $COMPOSE exec -T wordpress test -f /var/www/html/wp-settings.php >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 60 ]; then
    echo "ERROR: WordPress core files never appeared." >&2
    $COMPOSE logs --tail=50 wordpress >&2 || true
    exit 1
  fi
  sleep 2
done

# The image only copies core into /var/www/html when the volume is empty, so a
# newer `wordpress:latest` never reaches an existing site on its own: the suite
# would keep testing whatever version the volume was first created with. Sync
# core from the image whenever the two disagree. wp-content is left alone, and
# tar carries the image's www-data ownership across. Never `chown -R` the
# docroot: the plugin under test is bind-mounted into it, and that would take
# ownership of the working tree away from the developer.
image_version=$($COMPOSE exec -T wordpress php -r 'include "/usr/src/wordpress/wp-includes/version.php"; echo $wp_version;')
site_version=$($COMPOSE exec -T wordpress php -r 'include "/var/www/html/wp-includes/version.php"; echo $wp_version;')
if [ "$image_version" != "$site_version" ]; then
  echo ">>> Updating WordPress core ${site_version} -> ${image_version} from the image..."
  $COMPOSE exec -T wordpress bash -c \
    'tar -C /usr/src/wordpress --exclude=./wp-content -cf - . | tar -C /var/www/html -xf -'
fi

echo ">>> Installing WordPress (if needed)..."
if ! $WP_CLI core is-installed >/dev/null 2>&1; then
  $WP_CLI core install \
    --url="$SITE_URL" \
    --title="Members for Ko-fi Test" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
fi

# Brings the database schema up to the core version synced above (no-op when current).
$WP_CLI core update-db >/dev/null
# Keep the stored URL in sync if the port changed between runs.
$WP_CLI option update home "$SITE_URL" >/dev/null
$WP_CLI option update siteurl "$SITE_URL" >/dev/null

echo ">>> Disabling outgoing mail..."
$COMPOSE exec -T wordpress bash <<'BASH'
set -e
mkdir -p /var/www/html/wp-content/mu-plugins
cat > /var/www/html/wp-content/mu-plugins/disable-mail.php <<'PHP'
<?php
/**
 * Auto-created for the test environment: never send real mail.
 */
add_filter( 'pre_wp_mail', '__return_true' );
PHP
BASH

echo ">>> Activating plugin..."
$WP_CLI plugin activate members-for-kofi

echo ">>> Configuring plugin options (verification token: ${KOFI_TEST_TOKEN})..."
$WP_CLI option update members_for_kofi_options --format=json <<JSON
{
  "verification_token": "${KOFI_TEST_TOKEN}",
  "only_subscriptions": false,
  "tier_role_map": { "Gold": "editor", "Silver": "author", "Bronze": "contributor" },
  "default_role": "subscriber",
  "enable_expiry": true,
  "role_expiry_days": 35,
  "auto_clear_logs": true,
  "log_retention_days": 30
}
JSON

# WP-Cron fires loopback HTTP requests whenever a scheduled event is due. Those
# are full WordPress requests, so they run the plugin's schema upgrade check at
# unpredictable moments and can repair a broken schema behind a test's back.
# Cron behaviour is tested by invoking the hooks directly, so nothing is lost.
echo ">>> Disabling WP-Cron loopbacks (keeps tests deterministic)..."
$WP_CLI config set DISABLE_WP_CRON true --raw --type=constant >/dev/null

echo ">>> Setting permalinks (required for the /webhook-kofi endpoint)..."
$WP_CLI rewrite structure '/%postname%/' --hard >/dev/null
$WP_CLI rewrite flush --hard >/dev/null

echo ">>> Verifying the webhook endpoint responds..."
# Retry rather than checking once: on a cold machine (a CI runner, or the first
# boot after a reset) the web server can still be starting even though WP-CLI
# has already finished installing, and a single probe would fail the run for a
# reason that resolves itself a second later.
attempt=0
status=000
until [ "$status" != "000" ] && [ "$status" != "404" ]; do
  status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${SITE_URL}/webhook-kofi" --data 'data={}' || echo 000)
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo "ERROR: webhook endpoint not reachable (HTTP ${status})." >&2
    $COMPOSE logs --tail=50 wordpress >&2 || true
    exit 1
  fi
  [ "$status" = "000" ] || [ "$status" = "404" ] && sleep 2
done

cat <<EOF

Test site ready:
  URL:   ${SITE_URL}
  Admin: ${SITE_URL}/wp-admin/ (${ADMIN_USER} / ${ADMIN_PASS})
  Token: ${KOFI_TEST_TOKEN}
  WordPress: $($WP_CLI core version 2>/dev/null || echo unknown)
EOF
