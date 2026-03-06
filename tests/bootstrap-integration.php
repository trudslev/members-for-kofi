<?php
/**
 * PHPUnit bootstrap file for HTTP Integration tests.
 *
 * This file is part of the Members for Ko-fi plugin.
 *
 * Members for Ko-fi is free software: you can redistribute it and/or modify
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
 * @package MembersForKofi
 * @subpackage Tests
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load environment variables from .env file.
if ( file_exists( dirname( __DIR__ ) . '/.env' ) ) {
	$dotenv = Dotenv\Dotenv::createImmutable( dirname( __DIR__ ) );
	$dotenv->load();
	
	// Also set as putenv for getenv() compatibility.
	if ( isset( $_ENV['KOFI_VERIFICATION_TOKEN'] ) ) {
		putenv( 'KOFI_VERIFICATION_TOKEN=' . $_ENV['KOFI_VERIFICATION_TOKEN'] );
	}
	if ( isset( $_ENV['WP_TEST_SITE_URL'] ) ) {
		putenv( 'WP_TEST_SITE_URL=' . $_ENV['WP_TEST_SITE_URL'] );
	}
}

// Define minimal WordPress stubs if needed (for wp_json_encode).
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encode a variable into JSON.
	 *
	 * @param mixed $data    Variable to encode.
	 * @param int   $options Optional. Options to pass to json_encode().
	 * @param int   $depth   Optional. Maximum depth to walk through.
	 * @return string|false JSON encoded string, or false on failure.
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

echo "==> Integration tests bootstrap complete.\n";
echo "==> Tests will make HTTP requests to: " . ( getenv( 'WP_TEST_SITE_URL' ) ? getenv( 'WP_TEST_SITE_URL' ) : 'https://dev.foodgeek.dk' ) . "\n";
echo "==> Using verification token: " . ( getenv( 'KOFI_VERIFICATION_TOKEN' ) ? '****' . substr( getenv( 'KOFI_VERIFICATION_TOKEN' ), -6 ) : 'NOT SET' ) . "\n";
