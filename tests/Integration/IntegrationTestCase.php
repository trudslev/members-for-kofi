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
		$token     = getenv( 'KOFI_TEST_TOKEN' );

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
	 * Runs a WP-CLI command against the test site.
	 *
	 * @param string $args Arguments to pass to `wp`.
	 * @return string Trimmed stdout.
	 */
	protected static function wp( string $args ): string {
		$command = sprintf(
			'docker compose -f %s run --rm -T wpcli wp %s 2>/dev/null',
			escapeshellarg( dirname( __DIR__, 2 ) . '/docker-compose.test.yml' ),
			$args
		);

		return trim( (string) shell_exec( $command ) );
	}
}
