<?php
/**
 * PHPUnit bootstrap file for Ko-fi Members plugin.
 *
 * This file is part of the Ko-fi Members plugin.
 *
 * Ko-fi Members is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * @package KofiMembers
 * @subpackage Tests
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

// Set up the WordPress test environment directory.
$kofi_members_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $kofi_members_tests_dir ) {
	$kofi_members_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Handle PHPUnit Polyfills path if needed.
$kofi_members_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $kofi_members_polyfills_path ) {
	define( 'KOFI_MEMBERS_PHPUNIT_POLYFILLS_PATH', $kofi_members_polyfills_path );
}

// Validate that test functions are available.
if ( ! file_exists( "{$kofi_members_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$kofi_members_tests_dir}/includes/functions.php. Have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Load WordPress test functions.
require_once "{$kofi_members_tests_dir}/includes/functions.php";

/**
 * Load the plugin manually for tests.
 *
 * @return void
 */
function kofi_members_manually_load_plugin() {
	require dirname( __DIR__ ) . '/kofi-members.php';
}
tests_add_filter( 'muplugins_loaded', 'kofi_members_manually_load_plugin' );

// Start up the WordPress testing environment.
require "{$kofi_members_tests_dir}/includes/bootstrap.php";
