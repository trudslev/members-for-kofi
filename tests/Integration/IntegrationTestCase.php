<?php
/**
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
 */

namespace MembersForKofi\Tests\Integration;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that drive the running site over HTTP.
 *
 * Integration tests create real WordPress users as a side effect of posting real
 * donations, and nothing rolls that back the way the in-process suite can. Left
 * alone the account list grows on every run, so donor addresses handed out by
 * donor_email() are tracked and removed afterwards.
 */
abstract class IntegrationTestCase extends TestCase {

	/**
	 * Donor addresses created during the current test.
	 *
	 * @var array<string>
	 */
	private array $created_donors = array();

	/**
	 * Base URL of the site under test.
	 *
	 * @var string
	 */
	protected string $base_url;

	/**
	 * Verification token configured on the site under test.
	 *
	 * @var string
	 */
	protected string $token;

	/**
	 * Resolves the environment under test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$site_url = getenv( 'WP_TEST_SITE_URL' );
		$token    = getenv( 'KOFI_TEST_TOKEN' );

		$this->base_url = rtrim( false === $site_url || '' === $site_url ? 'http://localhost:8101' : $site_url, '/' );
		$this->token    = false === $token || '' === $token ? 'test-verification-token' : $token;
	}

	/**
	 * Removes the donors this test created.
	 */
	protected function tearDown(): void {
		if ( array() !== $this->created_donors ) {
			// One WP-CLI invocation for the lot: each one costs a container start.
			$args = implode( ' ', array_map( 'escapeshellarg', $this->created_donors ) );
			self::wp( 'user delete ' . $args . ' --yes' );

			$this->created_donors = array();
		}

		parent::tearDown();
	}

	/**
	 * Builds a unique donor address and schedules it for cleanup.
	 *
	 * @param string $prefix Label for the scenario.
	 * @return string
	 */
	protected function donor_email( string $prefix ): string {
		$email = sprintf( '%s-%s@example.com', $prefix, uniqid() );

		$this->created_donors[] = $email;

		return $email;
	}

	/**
	 * Whether the suite runs inside the test environment's WP-CLI container.
	 *
	 * `make test-integration` runs it there (WP_TEST_IN_STACK=1): WP-CLI and
	 * the database client are then local, and the site is reached by its
	 * service name. Without it, the suite runs on the host and reaches the
	 * containers through `docker compose`.
	 *
	 * @return bool
	 */
	public static function in_stack(): bool {
		return '1' === getenv( 'WP_TEST_IN_STACK' );
	}

	/**
	 * The command line that runs WP-CLI against the test site, without
	 * arguments.
	 *
	 * @return string
	 */
	public static function wp_prefix(): string {
		if ( self::in_stack() ) {
			return 'wp --path=/var/www/html';
		}

		return sprintf( 'docker compose -f %s exec -T wpcli wp', escapeshellarg( dirname( __DIR__, 2 ) . '/docker-compose.test.yml' ) );
	}

	/**
	 * The argv that runs the database's command-line client against the test
	 * database, followed by `$args`.
	 *
	 * Talks to MySQL directly rather than through WP-CLI: booting WordPress at
	 * all would run the plugin's schema upgrade.
	 *
	 * @param array<int,string> $args Client arguments, e.g. `-N`, `-e`, a statement.
	 * @return array<int,string>
	 */
	public static function db_argv( array $args ): array {
		$user = getenv( 'WORDPRESS_DB_USER' );
		$pass = getenv( 'WORDPRESS_DB_PASSWORD' );
		$name = getenv( 'WORDPRESS_TEST_DB_NAME' );

		$login = array(
			'-u' . ( false === $user || '' === $user ? 'wp' : $user ),
			'-p' . ( false === $pass || '' === $pass ? 'wp' : $pass ),
			false === $name || '' === $name ? 'wordpress_test' : $name,
		);

		// `mariadb` in the MariaDB image (the default), `mysql` in the MySQL one.
		$client = 'exec "$(command -v mariadb || command -v mysql)" "$@"';

		if ( self::in_stack() ) {
			$prefix = array( 'sh', '-c', $client, 'db-client', '-h', 'db', '--skip-ssl' );
		} else {
			$prefix = array( 'docker', 'compose', '-f', dirname( __DIR__, 2 ) . '/docker-compose.test.yml', 'exec', '-T', 'db', 'sh', '-c', $client, 'db-client' );
		}

		return array_merge( $prefix, $login, $args );
	}

	/**
	 * Runs a WP-CLI command against the test site.
	 *
	 * @param string $args Arguments to pass to `wp`.
	 * @return string Trimmed stdout.
	 */
	protected static function wp( string $args ): string {
		return trim( (string) shell_exec( self::wp_prefix() . ' ' . $args . ' 2>/dev/null' ) );
	}
}
