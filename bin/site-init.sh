#!/usr/bin/env bash
set -euo pipefail

# Load environment variables from .env if present (for WP_TEST_SITE_URL etc.)
if [ -f .env ]; then
  # Export all vars defined inside .env while preserving current shell options
  set -a
  . ./.env
  set +a
fi

WP_CLI="docker compose -f docker-compose.site.yml run --rm wpcli wp"

# Resolve site URL with fallback (compat with older WP_SITE_URL var if present)
SITE_URL=${WP_TEST_SITE_URL:-${WP_SITE_URL:-http://localhost:8100}}
ADMIN_USER=${WP_ADMIN_USER:-admin}
ADMIN_PASS=${WP_ADMIN_PASSWORD:-admin}
ADMIN_EMAIL=${WP_ADMIN_EMAIL:-admin@example.com}
TITLE=${WP_SITE_TITLE:-Members for Ko-fi Test}

# DB credentials (mirror docker-compose defaults)
DB_USER=${WORDPRESS_DB_USER:-wp}
DB_PASS=${WORDPRESS_DB_PASSWORD:-wp}
DB_NAME=${WORDPRESS_DB_NAME:-wordpress}
DISABLE_MAIL=${WP_TEST_DISABLE_MAIL:-1}

echo "Waiting for database service (user: $DB_USER, expected db: $DB_NAME)...";
attempt=0
until docker compose -f docker-compose.site.yml exec -T db mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; do
  attempt=$((attempt+1))
  if [ $attempt -ge 120 ]; then
    echo "Database not ready after $attempt attempts (~$((attempt*2))s)." >&2
    docker compose -f docker-compose.site.yml logs --tail=80 db >&2 || true
    exit 1
  fi
  sleep 2
done
echo "Database responding to ping.";

# Install WordPress if not installed (retry a few times to avoid race with initial volume extraction)
if ! $WP_CLI core is-installed >/dev/null 2>&1; then
  echo "Installing WordPress...";
  for i in 1 2 3; do
    if $WP_CLI core install \
        --url="$SITE_URL" \
        --title="$TITLE" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASS" \
        --admin_email="$ADMIN_EMAIL"; then
      break
    fi
    echo "Install attempt $i failed; retrying in 3s...";
    sleep 3
  done
fi

# Create must-use plugin to disable outgoing mail if requested
if [ "$DISABLE_MAIL" = "1" ]; then
  echo "Ensuring mail is disabled (WP_TEST_DISABLE_MAIL=1).";
  docker compose -f docker-compose.site.yml exec -T wordpress bash <<'BASH'
set -e
mkdir -p wp-content/mu-plugins
cat > wp-content/mu-plugins/disable-mail.php <<'PHP'
<?php
/**
 * Auto-created for test environment: disable actual mail sending.
 */
if ( ! function_exists( 'add_filter' ) ) { return; }
if ( ! defined( 'WP_TEST_DISABLE_MAIL' ) ) { define( 'WP_TEST_DISABLE_MAIL', true ); }
add_filter( 'pre_wp_mail', function( $null, $atts ) {
    $to = isset( $atts['to'] ) ? $atts['to'] : '';
    error_log( '[members-for-kofi test] wp_mail intercepted to: ' . $to );
    return true; // short-circuit: pretend sent successfully
}, 10, 2 );
PHP
BASH
fi

# Activate plugin
$WP_CLI plugin activate members-for-kofi || true

# Permalinks
$WP_CLI rewrite structure '/%postname%/' --hard
$WP_CLI rewrite flush --hard

# Output info
cat <<EOF
WordPress test site ready:
  URL:    $SITE_URL
  Admin:  $SITE_URL/wp-admin/
  User:   $ADMIN_USER
  Pass:   $ADMIN_PASS
EOF
