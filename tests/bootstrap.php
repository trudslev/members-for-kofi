<?php
/**
 * PHPUnit bootstrap: boots a real WordPress installation.
 *
 * Tests run inside the `wordpress:latest` container from
 * docker-compose.test.yml, against a real install of the newest WordPress
 * rather than the wordpress-develop test framework. That means the plugin is
 * exercised against the same core code, schema and plugin lifecycle that
 * production uses -- a test-only stand-in cannot drift from it.
 *
 * @package MembersForKofi
 * @subpackage Tests
 */

// WordPress expects these even when running under CLI.
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$members_for_kofi_wp_load_env = getenv( 'WP_LOAD_PATH' );
$members_for_kofi_wp_load     = ( false === $members_for_kofi_wp_load_env || '' === $members_for_kofi_wp_load_env )
	? '/var/www/html/wp-load.php'
	: $members_for_kofi_wp_load_env;

if ( ! file_exists( $members_for_kofi_wp_load ) ) {
	fwrite(
		STDERR,
		"ERROR: Could not find WordPress at {$members_for_kofi_wp_load}.\n" .
		"These tests run inside the test container. Use:\n\n" .
		"    make test\n\n" .
		"which boots the environment from docker-compose.test.yml.\n"
	);
	exit( 1 );
}

require_once $members_for_kofi_wp_load;

// Admin-side helpers the plugin uses (dbDelta, get_editable_roles, add_menu_page,
// wp_delete_user) are not loaded on a front-end request.
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/TestCase.php';

if ( ! class_exists( \MembersForKofi\Plugin::class ) ) {
	fwrite( STDERR, "ERROR: Plugin classes not autoloaded. Is the plugin activated in the test site?\n" );
	exit( 1 );
}

printf( "==> WordPress %s | PHP %s\n", get_bloginfo( 'version' ), PHP_VERSION );
