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

// Shared base class for the HTTP integration tests. Required explicitly because
// PHPUnit loads test files alphabetically and would otherwise hit a subclass first.
require_once __DIR__ . '/Integration/IntegrationTestCase.php';

// Load environment variables from .env file.
if ( file_exists( dirname( __DIR__ ) . '/.env' ) ) {
	$dotenv = Dotenv\Dotenv::createImmutable( dirname( __DIR__ ) );
	$dotenv->load();

	// Also set as putenv for getenv() compatibility.
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

// Clear any webhook failure counters left over from an earlier run: the
// endpoint sheds requests from an address that keeps failing, and two suites
// inside the same five-minute window would otherwise collide.
$members_for_kofi_compose = dirname( __DIR__ ) . '/docker-compose.test.yml';
shell_exec(
	sprintf(
		'docker compose -f %s run --rm -T wpcli wp eval %s 2>/dev/null',
		escapeshellarg( $members_for_kofi_compose ),
		escapeshellarg( 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%_transient_%members_for_kofi_wh_fail_%\'" );' )
	)
);

echo "==> Integration tests bootstrap complete.\n";
$members_for_kofi_site_url = getenv( 'WP_TEST_SITE_URL' );
$members_for_kofi_token    = getenv( 'KOFI_TEST_TOKEN' );
$members_for_kofi_site_url = ( false === $members_for_kofi_site_url || '' === $members_for_kofi_site_url )
	? 'http://localhost:8101'
	: $members_for_kofi_site_url;
$members_for_kofi_token    = ( false === $members_for_kofi_token || '' === $members_for_kofi_token )
	? 'test-verification-token'
	: $members_for_kofi_token;

// This suite posts real donations and creates real users. Pointed at a live
// site it would write to it, so anything that is not obviously a local test
// site has to be asked for explicitly.
//
// The guard exists because it already nearly happened: .env carried a
// WP_TEST_SITE_URL left over from when the suite did run against a remote site,
// and `make test-integration` was the only thing standing between that value
// and a live target. Running PHPUnit directly would have used it.
// parse_url(), not wp_parse_url(): this bootstrap runs on the host, outside
// WordPress, so the wrapper is not loaded.
$members_for_kofi_host = (string) parse_url( $members_for_kofi_site_url, PHP_URL_HOST );

if ( ! in_array( $members_for_kofi_host, array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' ), true )
	&& '1' !== getenv( 'KOFI_ALLOW_REMOTE_TESTS' ) ) {
	fwrite(
		STDERR,
		"\nRefusing to run the integration suite against '{$members_for_kofi_site_url}'.\n\n"
		. "These tests post real Ko-fi donations and create real WordPress users,\n"
		. "so they are only safe against a disposable local site. Use\n"
		. "`make test-integration`, which targets the local Docker environment.\n\n"
		. "If you genuinely mean to target that host, set KOFI_ALLOW_REMOTE_TESTS=1.\n\n"
	);
	exit( 1 );
}

echo '==> Target site: ' . $members_for_kofi_site_url . "\n";
echo '==> Test token:  ' . $members_for_kofi_token . "\n";
