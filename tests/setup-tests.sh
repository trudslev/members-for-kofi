#!/bin/bash
set -e

echo "$@" 

DB_NAME="${WORDPRESS_DB_NAME}"
DB_USER="${WORDPRESS_DB_USER}"
DB_PASS="${WORDPRESS_DB_PASSWORD}"
DB_HOST="${WORDPRESS_DB_HOST}"

TESTS_DIR="/tmp/wordpress-tests-lib"
PLUGIN_DIR="/var/www/html/wp-content/plugins/kofi-members"

echo ">>> Cloning WordPress develop repo..."
rm -rf "$TESTS_DIR"
mkdir -p "$TESTS_DIR"
if [ ! -d "/tmp/wordpress-develop" ]; then
    git clone --depth=1 https://github.com/WordPress/wordpress-develop.git /tmp/wordpress-develop
fi
cp -r /tmp/wordpress-develop/tests/phpunit/includes "$TESTS_DIR"
cp -r /tmp/wordpress-develop/tests/phpunit/data "$TESTS_DIR"
cp /tmp/wordpress-develop/wp-tests-config-sample.php "$TESTS_DIR/wp-tests-config.php"

# Create symlinks expected by the test suite
ln -sf /tmp/wordpress-develop/src /tmp/wordpress-tests-lib/src
ln -sf /tmp/wordpress-develop/tests/phpunit/includes /tmp/wordpress-tests-lib/includes
ln -sf /tmp/wordpress-develop/tests/phpunit/data /tmp/wordpress-tests-lib/data

cp /tmp/wordpress-develop/wp-tests-config-sample.php "$TESTS_DIR/wp-tests-config.php"

# Replace placeholders
sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$TESTS_DIR/wp-tests-config.php"
sed -i "s/yourusernamehere/$DB_USER/" "$TESTS_DIR/wp-tests-config.php"
sed -i "s/yourpasswordhere/$DB_PASS/" "$TESTS_DIR/wp-tests-config.php"
sed -i "s|localhost|$DB_HOST|" "$TESTS_DIR/wp-tests-config.php"
sed -i "s|define( 'ABSPATH.*|define( 'ABSPATH', '/tmp/wordpress-develop/src/' );|" "$TESTS_DIR/wp-tests-config.php"

# debug logging in dbDelta
#sed -i "s|\$wpdb->query( \$query );|\$wpdb->query( \$query ); if ( ! isset( \$wpdb ) \|\| ! is_object( \$wpdb ) ) { error_log( '\$wpdb is not initialized.' ); } else { error_log( '\$wpdb is initialized.' ); }|" /tmp/wordpress-develop/src/wp-admin/includes/upgrade.php

echo "==> Setup complete. Running PHPUnit."
"$PLUGIN_DIR/vendor/bin/phpunit" "$@" || { echo "PHPUnit failed"; exit 1; }