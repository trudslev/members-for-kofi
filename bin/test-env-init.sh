#!/usr/bin/env bash
# Provisions the throwaway WordPress test site used by `make test` and
# `make test-integration`, from scratch: the docroot and the database are both
# in RAM, so every start is a fresh site. Never touches a remote site.
#
#   bin/test-env-init.sh          PHPUnit only: database + WP-CLI container
#   TEST_ENV_HTTP=1 bin/...       also the Apache site on localhost:WP_TEST_PORT
#
# WordPress core comes from WP-CLI (`WP_VERSION`, default latest), so the suite
# follows new WordPress releases without pulling a new image.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ -f .env ]; then
  set -a
  . ./.env
  set +a
fi

COMPOSE="docker compose -f docker-compose.test.yml"
WP="$COMPOSE exec -T wpcli wp"

WP_VERSION=${WP_VERSION:-latest}
WP_TEST_PORT=${WP_TEST_PORT:-8101}
SITE_URL="http://localhost:${WP_TEST_PORT}"
ADMIN_USER=${WP_ADMIN_USER:-admin}
ADMIN_PASS=${WP_ADMIN_PASSWORD:-admin}
ADMIN_EMAIL=${WP_ADMIN_EMAIL:-admin@example.com}

# Fixed, non-secret token: this site is disposable and never public.
KOFI_TEST_TOKEN=${KOFI_TEST_TOKEN:-test-verification-token}

echo ">>> Starting the database and WP-CLI..."
$COMPOSE up -d --wait db wpcli >/dev/null

# Docker creates the directories above the plugin's mount point as root, so
# WordPress could not write into wp-content. Hand those two over to www-data.
# Not recursive: the plugin under test is bind-mounted below them, and taking
# ownership of the working tree away from the developer must never happen.
$COMPOSE exec -T -u 0 wpcli chown 33:33 /var/www/html/wp-content /var/www/html/wp-content/plugins

# Only the token's hash is stored, as the plugin itself would after a save.
KOFI_TEST_TOKEN_HASH=$(printf '%s' "$KOFI_TEST_TOKEN" | sha256sum | cut -d' ' -f1)
OPTIONS=$(cat <<JSON
{
  "verification_token_sha256": "${KOFI_TEST_TOKEN_HASH}",
  "only_subscriptions": false,
  "tier_role_map": { "Gold": "editor", "Silver": "author", "Bronze": "contributor" },
  "default_role": "subscriber",
  "enable_expiry": true,
  "role_expiry_days": 35,
  "auto_clear_logs": true,
  "log_retention_days": 30
}
JSON
)

echo ">>> Provisioning WordPress ${WP_VERSION} (verification token: ${KOFI_TEST_TOKEN})..."
# One exec for the lot: each `docker compose exec` costs most of a second.
$COMPOSE exec -T \
  -e WP_VERSION="$WP_VERSION" -e SITE_URL="$SITE_URL" \
  -e ADMIN_USER="$ADMIN_USER" -e ADMIN_PASS="$ADMIN_PASS" -e ADMIN_EMAIL="$ADMIN_EMAIL" \
  -e DB_NAME="${WORDPRESS_TEST_DB_NAME:-wordpress_test}" \
  -e DB_USER="${WORDPRESS_DB_USER:-wp}" -e DB_PASS="${WORDPRESS_DB_PASSWORD:-wp}" \
  -e OPTIONS="$OPTIONS" \
  wpcli sh -eu -s <<'SH'
cd /var/www/html

if [ ! -f wp-includes/version.php ]; then
  # Unpacking the archive needs more than PHP's default 128 MB.
  php -d memory_limit=256M /usr/local/bin/wp core download --version="$WP_VERSION" --quiet
fi

if [ ! -f wp-config.php ]; then
  wp config create --dbhost=db:3306 --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --skip-check --quiet
  wp config set WP_DEBUG true --raw --type=constant >/dev/null
  # WP-Cron fires loopback HTTP requests whenever a scheduled event is due.
  # Those are full WordPress requests, so they run the plugin's schema upgrade
  # check at unpredictable moments and can repair a broken schema behind a
  # test's back. Cron is tested by invoking the hooks directly.
  wp config set DISABLE_WP_CRON true --raw --type=constant >/dev/null
fi

if ! wp core is-installed >/dev/null 2>&1; then
  wp core install --url="$SITE_URL" --title="Members for Ko-fi Test" \
    --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" --admin_email="$ADMIN_EMAIL" \
    --skip-email >/dev/null
fi

# Never send real mail.
mkdir -p wp-content/mu-plugins
cat > wp-content/mu-plugins/disable-mail.php <<'PHP'
<?php
/**
 * Auto-created for the test environment: never send real mail.
 */
add_filter( 'pre_wp_mail', '__return_true' );
PHP

wp plugin activate members-for-kofi >/dev/null

# The options the suites expect, and the stored URL in sync with the port.
wp eval '
  update_option( "home", getenv( "SITE_URL" ) );
  update_option( "siteurl", getenv( "SITE_URL" ) );
  update_option( "members_for_kofi_options", json_decode( getenv( "OPTIONS" ), true ) );
'

# Pretty permalinks, required for the /webhook-kofi endpoint and the HTTP suite.
# Also flushes the rules.
wp rewrite structure '/%postname%/' --hard >/dev/null

# Apache routes pretty URLs to WordPress only through .htaccess. The Apache
# image writes one when it copies in its own core; core comes from WP-CLI here,
# so write WordPress's standard rules.
cat > .htaccess <<'HTACCESS'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS
SH

if [ "${TEST_ENV_HTTP:-0}" = 1 ]; then
  echo ">>> Starting the web server..."
  $COMPOSE --profile http up -d --wait wordpress >/dev/null

  echo ">>> Verifying the webhook endpoint responds..."
  # Retry rather than checking once: Apache can still be starting.
  attempt=0
  status=000
  until [ "$status" != "000" ] && [ "$status" != "404" ]; do
    status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${SITE_URL}/webhook-kofi" --data 'data={}' || echo 000)
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
      echo "ERROR: webhook endpoint not reachable (HTTP ${status})." >&2
      $COMPOSE --profile http logs --tail=50 wordpress >&2 || true
      exit 1
    fi
    if [ "$status" = "000" ] || [ "$status" = "404" ]; then sleep 1; fi
  done
fi

echo ">>> Test site ready: WordPress $($WP core version 2>/dev/null || echo unknown), $($COMPOSE exec -T db sh -c 'mariadbd --version 2>/dev/null || mysqld --version' | sed -E 's/.*Ver ([^ ]+).*/\1/' | head -1)"
if [ "${TEST_ENV_HTTP:-0}" = 1 ]; then
  echo "    ${SITE_URL}/wp-admin/ (${ADMIN_USER} / ${ADMIN_PASS}), token: ${KOFI_TEST_TOKEN}"
fi
