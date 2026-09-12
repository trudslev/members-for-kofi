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

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

/**
 * Proves that a site left behind by an older release repairs itself.
 *
 * The in-process suite calls Plugin::maybe_upgrade() directly. That shows the
 * routine works, but not that a real visitor hitting a real page triggers it.
 * This drives the actual upgrade path: break the schema the way a 1.0.x install
 * would have it, request a page over HTTP, and check the site healed and can
 * log donations again.
 */
class SchemaUpgradeTest extends IntegrationTestCase {

	/**
	 * Makes sure the site is left in a healthy state whatever happens.
	 */
	protected function tearDown(): void {
		self::wp( 'eval ' . escapeshellarg( 'MembersForKofi\Plugin::activate();' ) );

		parent::tearDown();
	}

	/**
	 * Runs SQL straight against the test database.
	 *
	 * The fixture deliberately avoids WP-CLI: booting WordPress at all -- even
	 * with this plugin skipped -- recreates the very table the test needs to be
	 * missing. Talking to MySQL directly leaves the HTTP request under test as
	 * the only thing that can repair the schema.
	 *
	 * @param string $sql Statement to run.
	 * @return string Trimmed stdout.
	 */
	private static function db_query( string $sql ): string {
		$user = getenv( 'WORDPRESS_DB_USER' );
		$pass = getenv( 'WORDPRESS_DB_PASSWORD' );
		$name = getenv( 'WORDPRESS_TEST_DB_NAME' );

		$command = sprintf(
			'docker compose -f %s exec -T db mysql -u%s -p%s %s -N -B -e %s 2>/dev/null',
			escapeshellarg( dirname( __DIR__, 2 ) . '/docker-compose.test.yml' ),
			escapeshellarg( false === $user || '' === $user ? 'wp' : $user ),
			escapeshellarg( false === $pass || '' === $pass ? 'wp' : $pass ),
			escapeshellarg( false === $name || '' === $name ? 'wordpress_test' : $name ),
			escapeshellarg( $sql )
		);

		return trim( (string) shell_exec( $command ) );
	}

	/**
	 * Puts the site back to how a 1.0.x install looked: no schema version
	 * recorded, and no request log table at all.
	 */
	private function simulate_legacy_install(): void {
		self::db_query( 'DROP TABLE IF EXISTS wp_members_for_kofi_request_logs' );
		self::db_query( "DELETE FROM wp_options WHERE option_name = 'members_for_kofi_db_version'" );

		$this->assertFalse(
			$this->request_log_table_exists(),
			'Fixture failed: the request log table should be gone before the upgrade runs.'
		);
	}

	/**
	 * Asks the database whether the request log table is present.
	 *
	 * @return bool
	 */
	private function request_log_table_exists(): bool {
		return '' !== self::db_query( "SHOW TABLES LIKE 'wp_members_for_kofi_request_logs'" );
	}

	/**
	 * Requests a page over HTTP, the way any visitor or bot would.
	 *
	 * @return int HTTP status code.
	 */
	private function request_home_page(): int {
		$curl = curl_init( $this->base_url . '/' );

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);

		curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$error  = curl_error( $curl );

		unset( $curl );

		if ( '' !== $error ) {
			$this->fail( "cURL error talking to {$this->base_url}: {$error}" );
		}

		return $status;
	}

	/**
	 * Posts a real Ko-fi style donation.
	 *
	 * @param array $payload Donation payload.
	 * @return int HTTP status code.
	 */
	private function post_donation( array $payload ): int {
		$curl = curl_init( $this->base_url . '/webhook-kofi' );

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => 'data=' . rawurlencode( json_encode( $payload ) ),
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/x-www-form-urlencoded',
					'User-Agent: Ko-fi',
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);

		curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$error  = curl_error( $curl );

		unset( $curl );

		if ( '' !== $error ) {
			$this->fail( "cURL error talking to {$this->base_url}: {$error}" );
		}

		return $status;
	}

	/**
	 * Counts request log rows recorded for an address.
	 *
	 * @param string $email Donor address.
	 * @return int
	 */
	private function logged_request_count( string $email ): int {
		return (int) self::db_query(
			sprintf(
				"SELECT COUNT(*) FROM wp_members_for_kofi_request_logs WHERE email = '%s'",
				// Addresses come from donor_email(), so this is uniqid output, not user input.
				addslashes( $email )
			)
		);
	}

	/**
	 * An ordinary page request brings an outdated install's schema up to date.
	 */
	public function test_a_page_request_upgrades_an_outdated_install(): void {
		$this->simulate_legacy_install();

		$status = $this->request_home_page();

		$this->assertSame( 200, $status, 'Expected the site to still serve pages while upgrading' );
		$this->assertTrue(
			$this->request_log_table_exists(),
			'Expected a normal page request to create the missing request log table'
		);
	}

	/**
	 * The repaired install can log donations again.
	 *
	 * This is the behaviour the missing table actually cost users: donations
	 * were processed but nothing reached the Request log.
	 */
	public function test_donations_are_logged_again_after_the_upgrade(): void {
		$this->simulate_legacy_install();
		$this->request_home_page();

		$email = $this->donor_email( 'schema-upgrade' );

		$status = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'amount'                  => '25.00',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 200, $status, 'Expected the donation to be accepted' );
		$this->assertSame(
			1,
			$this->logged_request_count( $email ),
			'Expected the donation to be written to the restored request log table'
		);
	}

	/**
	 * A real donation stores no part of the verification token.
	 *
	 * The 1.1.x development line wrote the first ten characters of the site's
	 * live token to a verification_token column on every request -- never
	 * displayed, never queried, just a fragment of a secret accumulating.
	 */
	public function test_a_real_donation_stores_no_part_of_the_token(): void {
		$email = $this->donor_email( 'token-at-rest' );

		$status = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'amount'                  => '25.00',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 200, $status, 'Expected the donation to be accepted' );

		$row = self::db_query(
			sprintf(
				"SELECT * FROM wp_members_for_kofi_request_logs WHERE email = '%s'",
				addslashes( $email )
			)
		);

		$this->assertNotSame( '', $row, 'Expected the donation to be logged at all' );

		// Ten characters is what the old code kept; check for it plus the whole
		// token, so neither a fragment nor the full value can pass unnoticed.
		$this->assertStringNotContainsString(
			substr( $this->token, 0, 10 ),
			$row,
			'The stored request log row still contains a fragment of the verification token'
		);
		$this->assertStringNotContainsString(
			$this->token,
			$row,
			'The stored request log row still contains the verification token'
		);
	}

	/**
	 * The column that used to hold the fragment is gone from the live schema.
	 */
	public function test_the_legacy_token_column_is_absent(): void {
		$columns = self::db_query( 'SHOW COLUMNS FROM wp_members_for_kofi_request_logs' );

		$this->assertStringNotContainsString(
			'verification_token',
			$columns,
			'The verification_token column should no longer exist'
		);
	}

	/**
	 * The schema version is recorded, so the upgrade does not repeat forever.
	 */
	public function test_the_upgrade_records_its_version(): void {
		$this->simulate_legacy_install();
		$this->request_home_page();

		$recorded = self::db_query(
			"SELECT option_value FROM wp_options WHERE option_name = 'members_for_kofi_db_version'"
		);

		$this->assertSame(
			'3',
			$recorded,
			'Expected the site to record the schema version it upgraded to'
		);
	}
}
