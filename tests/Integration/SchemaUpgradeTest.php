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
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

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
	 * The plugin options row as it was before the test, hex-encoded.
	 *
	 * @var string
	 */
	private string $options_snapshot = '';

	/**
	 * Remembers the site's configured options, byte for byte.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->options_snapshot = self::db_query(
			"SELECT HEX(option_value) FROM wp_options WHERE option_name = 'members_for_kofi_options'"
		);
	}

	/**
	 * Makes sure the site is left in a healthy state whatever happens.
	 */
	protected function tearDown(): void {
		// Restore the token configuration exactly, before anything boots
		// WordPress: the other integration tests share this install.
		if ( '' !== $this->options_snapshot ) {
			self::write_options( hex2bin( $this->options_snapshot ) );
		}

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
	 * Replaces the stored plugin options with a raw serialized value.
	 *
	 * Hex-encoded so no quoting of the serialized string can go wrong.
	 *
	 * @param string $serialized Serialized option value.
	 * @return void
	 */
	private static function write_options( string $serialized ): void {
		self::db_query(
			'UPDATE wp_options SET option_value = 0x' . bin2hex( $serialized ) . " WHERE option_name = 'members_for_kofi_options'"
		);
	}

	/**
	 * The plugin options as currently stored.
	 *
	 * @return array
	 */
	private static function read_options(): array {
		$hex = self::db_query( "SELECT HEX(option_value) FROM wp_options WHERE option_name = 'members_for_kofi_options'" );

		$options = unserialize( (string) hex2bin( $hex ), array( 'allowed_classes' => false ) );

		return is_array( $options ) ? $options : array();
	}

	/**
	 * Puts the site where a 1.1.0 install is: the given schema version, and
	 * the token in plaintext rather than hashed.
	 *
	 * Written straight to MySQL: booting WordPress to write it -- WP-CLI
	 * included -- would run the migration and leave nothing to test.
	 *
	 * @param string $db_version Schema version to record.
	 * @return void
	 */
	private function simulate_plaintext_token_install( string $db_version ): void {
		$options = unserialize( (string) hex2bin( $this->options_snapshot ), array( 'allowed_classes' => false ) );
		$this->assertIsArray( $options, 'Fixture failed: could not read the configured options.' );

		unset( $options['verification_token_sha256'] );
		$options['verification_token'] = $this->token;

		self::write_options( serialize( $options ) );
		self::db_query( "UPDATE wp_options SET option_value = '" . $db_version . "' WHERE option_name = 'members_for_kofi_db_version'" );

		$stored = self::read_options();
		$this->assertSame( $this->token, $stored['verification_token'] ?? null, 'Fixture failed: the plaintext token was not stored.' );
		$this->assertArrayNotHasKey( 'verification_token_sha256', $stored, 'Fixture failed: a hash is still stored.' );
	}

	/**
	 * Asserts the stored options hold the hash of the test token and no
	 * plaintext.
	 *
	 * @return void
	 */
	private function assert_token_migrated(): void {
		$options = self::read_options();

		$this->assertSame( hash( 'sha256', $this->token ), $options['verification_token_sha256'] ?? null, 'Expected the token to be stored as its hash.' );
		$this->assertArrayNotHasKey( 'verification_token', $options, 'Expected the plaintext token to be gone.' );
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
			'6',
			$recorded,
			'Expected the site to record the schema version it upgraded to'
		);
	}

	/**
	 * The update must not break a working site, and Ko-fi's own request is
	 * enough to migrate it: a 1.1.0 install, updated, whose first request
	 * after the update is a donation -- nobody has opened wp-admin.
	 */
	public function test_a_donation_is_the_first_request_after_the_update(): void {
		$this->simulate_plaintext_token_install( '3' );

		$email  = $this->donor_email( 'webhook-first' );
		$status = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'amount'                  => '5.00',
				'is_subscription_payment' => false,
			)
		);

		$this->assertSame( 200, $status, 'The donation must be accepted with the token Ko-fi already sends.' );
		$this->assert_token_migrated();
		$this->assertSame(
			'6',
			self::db_query( "SELECT option_value FROM wp_options WHERE option_name = 'members_for_kofi_db_version'" )
		);
		$this->assertSame( 1, $this->logged_request_count( $email ) );
	}

	/**
	 * The fallback over real HTTP: the schema version is already current, so
	 * the upgrade on init does nothing, and the webhook itself has to verify
	 * against the plaintext and migrate it.
	 */
	public function test_a_plaintext_token_left_behind_is_verified_and_migrated_by_the_webhook(): void {
		$this->simulate_plaintext_token_install( '4' );

		$status = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $this->donor_email( 'fallback' ),
				'tier_name'               => 'Gold',
				'amount'                  => '5.00',
				'is_subscription_payment' => false,
			)
		);

		$this->assertSame( 200, $status );
		$this->assert_token_migrated();
	}

	/**
	 * A wrong token on a not-yet-migrated site is still refused, and the
	 * right one still works afterwards.
	 */
	public function test_a_wrong_token_is_refused_on_a_plaintext_install(): void {
		$this->simulate_plaintext_token_install( '4' );

		$wrong = $this->post_donation(
			array(
				'verification_token' => 'not-the-token',
				'email'              => $this->donor_email( 'fallback-wrong' ),
			)
		);
		$right = $this->post_donation(
			array(
				'verification_token' => $this->token,
				'email'              => $this->donor_email( 'fallback-right' ),
			)
		);

		$this->assertSame( 401, $wrong );
		$this->assertSame( 200, $right );
	}

	/**
	 * A donor account made before 1.3.0 loses the email from its public
	 * fields on the first page view after the update.
	 */
	public function test_a_page_view_removes_the_email_from_old_donor_accounts(): void {
		$email = $this->donor_email( 'legacy-public' );

		// Made the way the old code made them; WP-CLI boots WordPress, so the
		// schema version is lowered only afterwards.
		$id = (int) self::wp( 'user create ' . escapeshellarg( $email ) . ' ' . escapeshellarg( $email ) . ' --display_name=' . escapeshellarg( $email ) . ' --porcelain' );
		self::wp( 'user meta update ' . $id . ' kofi_donation_assigned_role subscriber' );
		self::db_query( "UPDATE wp_options SET option_value = '4' WHERE option_name = 'members_for_kofi_db_version'" );

		$this->assertSame( $email, self::db_query( 'SELECT display_name FROM wp_users WHERE ID = ' . $id ), 'Fixture failed.' );

		$this->assertSame( 200, $this->request_home_page() );

		$this->assertSame( 'Supporter', self::db_query( 'SELECT display_name FROM wp_users WHERE ID = ' . $id ) );
		$this->assertStringStartsWith( 'kofi-', self::db_query( 'SELECT user_nicename FROM wp_users WHERE ID = ' . $id ) );
		$this->assertSame( '6', self::db_query( "SELECT option_value FROM wp_options WHERE option_name = 'members_for_kofi_db_version'" ) );
	}

	/**
	 * Requests a page and reports how long it took.
	 *
	 * @param string $path Path below the site URL.
	 * @return array{0:int,1:float} Status and seconds.
	 */
	private function timed_get( string $path ): array {
		$curl = curl_init( $this->base_url . $path );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		curl_exec( $curl );
		$result = array( (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE ), (float) curl_getinfo( $curl, CURLINFO_TOTAL_TIME ) );
		unset( $curl );

		return $result;
	}

	/**
	 * While another process holds the upgrade, pages answer at once and the
	 * upgrade is left to it; once it lets go, the next request upgrades.
	 *
	 * The lock is held by a separate MySQL session, as a slow upgrading
	 * request would hold it. In 1.3.0 each request started its own copy of
	 * the upgrade instead, and foodgeek.io jammed.
	 */
	public function test_pages_answer_while_another_process_upgrades(): void {
		self::db_query( "UPDATE wp_options SET option_value = '5' WHERE option_name = 'members_for_kofi_db_version'" );

		$name   = 'mfk_up_' . md5( 'wordpress_test|wp_' );
		$holder = proc_open(
			array( 'docker', 'compose', '-f', dirname( __DIR__, 2 ) . '/docker-compose.test.yml', 'exec', '-T', 'db', 'mysql', '-uwp', '-pwp', 'wordpress_test', '-N', '-e', "SELECT GET_LOCK('{$name}', 0); SELECT SLEEP(12);" ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $holder );

		try {
			// Wait until the other session really holds the lock.
			$held = '';
			for ( $i = 0; $i < 40; $i++ ) {
				$held = self::db_query( "SELECT IS_USED_LOCK('{$name}') IS NOT NULL" );
				if ( '1' === $held ) {
					break;
				}
				usleep( 250000 );
			}
			$this->assertSame( '1', $held, 'Fixture: the lock must be held.' );

			list( $status, $seconds ) = $this->timed_get( '/?locked=' . uniqid() );
			$this->assertSame( 200, $status );
			$this->assertLessThan( 3.0, $seconds, 'A page must not wait for another process\'s upgrade.' );
			$this->assertSame( '5', self::db_query( "SELECT option_value FROM wp_options WHERE option_name = 'members_for_kofi_db_version'" ) );
		} finally {
			foreach ( $pipes as $pipe ) {
				fclose( $pipe );
			}
			proc_close( $holder );
		}

		$this->timed_get( '/?after=' . uniqid() );
		$this->assertSame( '6', self::db_query( "SELECT option_value FROM wp_options WHERE option_name = 'members_for_kofi_db_version'" ) );
	}

	/**
	 * Many simultaneous first requests after an update all answer, and the
	 * site ends up upgraded.
	 */
	public function test_simultaneous_requests_after_an_update_all_answer(): void {
		self::db_query( "UPDATE wp_options SET option_value = '4' WHERE option_name = 'members_for_kofi_db_version'" );

		$multi   = curl_multi_init();
		$handles = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ch = curl_init( $this->base_url . '/?burst=' . $i . uniqid() );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 30,
				)
			);
			curl_multi_add_handle( $multi, $ch );
			$handles[] = $ch;
		}
		do {
			curl_multi_exec( $multi, $running );
			curl_multi_select( $multi );
		} while ( $running > 0 );

		$statuses = array();
		foreach ( $handles as $ch ) {
			$statuses[] = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_multi_remove_handle( $multi, $ch );
		}
		curl_multi_close( $multi );

		$this->assertSame( array_fill( 0, 12, 200 ), $statuses );
		$this->assertSame( '6', self::db_query( "SELECT option_value FROM wp_options WHERE option_name = 'members_for_kofi_db_version'" ) );
	}
}
