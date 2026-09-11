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

echo ">>> Setting permalinks (required for the /webhook-kofi endpoint)..."
$WP_CLI rewrite structure '/%postname%/' --hard >/dev/null
$WP_CLI rewrite flush --hard >/dev/null

echo ">>> Verifying the webhook endpoint responds..."
status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${SITE_URL}/webhook-kofi" --data 'data={}' || echo 000)
if [ "$status" = "000" ] || [ "$status" = "404" ]; then
  echo "ERROR: webhook endpoint not reachable (HTTP ${status})." >&2
  exit 1
fi

cat <<EOF

Test site ready:
  URL:   ${SITE_URL}
  Admin: ${SITE_URL}/wp-admin/ (${ADMIN_USER} / ${ADMIN_PASS})
  Token: ${KOFI_TEST_TOKEN}
  WordPress: $($WP_CLI core version 2>/dev/null || echo unknown)
EOF
