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
 * @package Ko-fiMembers
 */

namespace MembersForKofi\Tests;

use MembersForKofi\Logging\RequestLogger;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Plugin;
use MembersForKofi\Webhook\VerificationToken;
use MembersForKofi\Webhook\Webhook;

/**
 * Covers the database schema upgrade path.
 *
 * WordPress never fires the activation hook when a plugin is updated in place,
 * so anything created at activation has to be reconciled on a later request.
 * A site that installed 1.0.x and then updated had no request log table at all;
 * these tests pin down that this no longer happens.
 */
class UpgradeTest extends TestCase {

	/**
	 * The schema version recorded before the current test ran.
	 *
	 * @var mixed
	 */
	private $original_db_version = false;

	/**
	 * Remembers the recorded schema version.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_db_version = get_option( Plugin::DB_VERSION_OPTION, false );
	}

	/**
	 * Restores the schema version and makes sure the tables are back.
	 */
	protected function tearDown(): void {
		UserLogger::create_table();
		RequestLogger::create_table();

		if ( false === $this->original_db_version ) {
			delete_option( Plugin::DB_VERSION_OPTION );
		} else {
			update_option( Plugin::DB_VERSION_OPTION, $this->original_db_version );
		}

		parent::tearDown();
	}

	/**
	 * Reports whether a table currently exists.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table, checking existence.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * The request log table name.
	 *
	 * @return string
	 */
	private function request_log_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'members_for_kofi_request_logs';
	}

	/**
	 * Puts the site into the state a 1.0.x install would be in: no recorded
	 * schema version, and no request log table.
	 */
	private function simulate_pre_request_log_install(): void {
		global $wpdb;

		delete_option( Plugin::DB_VERSION_OPTION );

		$table = $this->request_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, test fixture.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );

		$this->assertFalse(
			$this->table_exists( $table ),
			'Fixture failed: the request log table should be gone before the upgrade runs.'
		);
	}

	/**
	 * An install that predates the request log table gets it on upgrade.
	 *
	 * This is the bug: updating from 1.0.x through WordPress.org never ran the
	 * activation hook, so the table was simply never created and every webhook
	 * request failed to log.
	 */
	public function test_upgrade_creates_a_table_missing_since_an_older_install(): void {
		$this->simulate_pre_request_log_install();

		Plugin::maybe_upgrade();

		$this->assertTrue(
			$this->table_exists( $this->request_log_table() ),
			'Expected maybe_upgrade() to create the missing request log table'
		);
	}

	/**
	 * After upgrading, the schema version is recorded so it does not run again.
	 */
	public function test_upgrade_records_the_schema_version(): void {
		$this->simulate_pre_request_log_install();

		Plugin::maybe_upgrade();

		$this->assertSame(
			Plugin::DB_VERSION,
			get_option( Plugin::DB_VERSION_OPTION ),
			'Expected the installed schema version to be recorded'
		);
	}

	/**
	 * Upgrading must not discard logs a site has already collected.
	 */
	public function test_upgrade_preserves_existing_log_rows(): void {
		global $wpdb;

		RequestLogger::create_table();

		$table = $this->request_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned table, seeding a fixture.
		$wpdb->insert(
			$table,
			array(
				'email'     => 'keep-me@example.com',
				'tier_name' => 'Gold',
				'success'   => 1,
				'timestamp' => current_time( 'mysql' ),
			)
		);

		delete_option( Plugin::DB_VERSION_OPTION );

		Plugin::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '` WHERE email = %s', 'keep-me@example.com' )
		);

		$this->assertSame( 1, (int) $found, 'Upgrading must not destroy existing log rows' );
	}

	/**
	 * When the schema is already current the upgrade does no database work.
	 *
	 * Without the early return this would run dbDelta on every single request.
	 */
	public function test_upgrade_is_skipped_when_the_schema_is_current(): void {
		update_option( Plugin::DB_VERSION_OPTION, Plugin::DB_VERSION );

		global $wpdb;
		$table = $this->request_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, test fixture.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );

		Plugin::maybe_upgrade();

		$this->assertFalse(
			$this->table_exists( $table ),
			'Expected maybe_upgrade() to short-circuit when the recorded version is current'
		);
	}

	/**
	 * The upgrade check is actually wired up to run.
	 *
	 * Everything else here tests the routine in isolation; if it is never
	 * hooked, no site ever upgrades and the bug is entirely unfixed.
	 */
	public function test_upgrade_check_is_hooked_on_init(): void {
		$plugin = new Plugin();

		$this->assertNotFalse(
			has_action( 'init', array( Plugin::class, 'maybe_upgrade' ) ),
			'Expected maybe_upgrade() to be hooked on init'
		);

		unset( $plugin );
	}

	/**
	 * Re-adds the verification_token column as the 1.1.x line declared it, and
	 * stores a token fragment in it the way that release did.
	 *
	 * @param string $fragment Value to store.
	 * @return void
	 */
	private function restore_legacy_token_column( string $fragment ): void {
		global $wpdb;

		$table = $this->request_log_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, test fixture.
		$wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ADD COLUMN `verification_token` VARCHAR(20) DEFAULT NULL' );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO `' . esc_sql( $table ) . '` (`email`, `verification_token`, `payload`, `status_code`, `success`, `timestamp`) VALUES (%s, %s, %s, %d, %d, %s)',
				'legacy@example.com',
				$fragment,
				'{}',
				200,
				1,
				current_time( 'mysql' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Reports whether the request log table still has the token column.
	 *
	 * @return bool
	 */
	private function token_column_exists(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $this->request_log_table() ) . '` LIKE %s', 'verification_token' )
		);
	}

	/**
	 * Upgrading removes the column that held a fragment of the real token.
	 *
	 * The 1.1.x development line stored the first ten characters of the site's
	 * Ko-fi verification token on every request. Dropping the column is what
	 * destroys the historic fragments; blanking new writes alone would leave
	 * every previously logged request still holding part of the secret.
	 */
	public function test_upgrade_drops_the_legacy_verification_token_column(): void {
		$this->restore_legacy_token_column( '81c20f40-9...' );

		$this->assertTrue( $this->token_column_exists(), 'Fixture failed: the legacy column should be present.' );

		delete_option( Plugin::DB_VERSION_OPTION );
		Plugin::maybe_upgrade();

		$this->assertFalse(
			$this->token_column_exists(),
			'Expected the upgrade to drop the verification_token column'
		);
	}

	/**
	 * Dropping the column takes the stored fragments with it.
	 */
	public function test_upgrade_destroys_stored_token_fragments(): void {
		global $wpdb;

		$this->restore_legacy_token_column( 'sekret-abc...' );

		delete_option( Plugin::DB_VERSION_OPTION );
		Plugin::maybe_upgrade();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $this->request_log_table() ) . '` WHERE email = %s',
				'legacy@example.com'
			),
			ARRAY_A
		);

		$this->assertNotNull( $row, 'The log row itself must survive the upgrade' );
		$this->assertArrayNotHasKey(
			'verification_token',
			$row,
			'No column should remain that could hold a token fragment'
		);
		$this->assertStringNotContainsString(
			'sekret-abc',
			wp_json_encode( $row ),
			'No trace of the stored token fragment should survive'
		);
	}

	/**
	 * Dropping a column that is already gone must not error.
	 */
	public function test_upgrade_is_safe_when_the_column_is_already_absent(): void {
		delete_option( Plugin::DB_VERSION_OPTION );

		Plugin::maybe_upgrade();
		Plugin::maybe_upgrade();

		$this->assertFalse( $this->token_column_exists() );
	}

	/**
	 * A fresh activation records the schema version too, so the upgrade path
	 * does not immediately re-run on a brand new install.
	 */
	public function test_activation_records_the_schema_version(): void {
		delete_option( Plugin::DB_VERSION_OPTION );

		Plugin::activate();

		$this->assertSame(
			Plugin::DB_VERSION,
			get_option( Plugin::DB_VERSION_OPTION ),
			'Expected activate() to record the installed schema version'
		);
	}

	/**
	 * Uninstalling takes the per-user tracking meta with it.
	 *
	 * The tables and the options were already cleaned up, but two meta keys were
	 * left behind on every supporter the plugin had ever touched.
	 */
	public function test_uninstall_removes_the_per_user_tracking_meta(): void {
		$user_id = $this->create_user();
		update_user_meta( $user_id, 'kofi_role_assigned_at', time() );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );

		Plugin::uninstall();

		$this->assertSame(
			'',
			get_user_meta( $user_id, 'kofi_role_assigned_at', true ),
			'Uninstall must not leave the expiry timestamp behind'
		);
		$this->assertSame(
			'',
			get_user_meta( $user_id, 'kofi_donation_assigned_role', true ),
			'Uninstall must not leave the tracked role behind'
		);
	}

	/**
	 * Uninstalling does not strip roles from supporters.
	 *
	 * Removing a plugin should not quietly revoke access the site owner granted.
	 */
	public function test_uninstall_leaves_user_roles_alone(): void {
		$user_id = $this->create_user();
		$user    = get_user_by( 'ID', $user_id );
		$user->add_role( 'editor' );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'editor' );

		Plugin::uninstall();

		$user = get_user_by( 'ID', $user_id );
		$this->assertContains( 'editor', $user->roles );
	}

	/**
	 * Options as a pre-1.2.0 install stored them: the token in plaintext.
	 *
	 * @param mixed $token Value of the legacy token key.
	 * @return array
	 */
	private function legacy_options( $token = 'legacy-token' ): array {
		return array(
			'verification_token' => $token,
			'only_subscriptions' => false,
			'tier_role_map'      => array(),
			'default_role'       => 'subscriber',
			'enable_expiry'      => true,
			'role_expiry_days'   => 35,
			'auto_clear_logs'    => true,
			'log_retention_days' => 30,
		);
	}

	/**
	 * Puts the site in the state a 1.1.0 install is in: schema version 3 and a
	 * plaintext token.
	 *
	 * @param mixed $token Value of the legacy token key.
	 */
	private function simulate_plaintext_token_install( $token = 'legacy-token' ): void {
		update_option( Plugin::DB_VERSION_OPTION, '3' );
		$this->write_options_raw( $this->legacy_options( $token ) );
	}

	/**
	 * The options exactly as stored, with no cache in the way.
	 *
	 * @return mixed
	 */
	private function stored_options() {
		wp_cache_delete( 'members_for_kofi_options', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return get_option( 'members_for_kofi_options' );
	}

	/**
	 * Sends a donation straight to the handler.
	 *
	 * @param mixed $token Token to send.
	 * @return \WP_REST_Response
	 */
	private function donate( $token ): \WP_REST_Response {
		return ( new Webhook() )->handle(
			null,
			array(
				'verification_token' => $token,
				'email'              => 'upgrade-' . wp_generate_password( 8, false, false ) . '@example.com',
				'tier_name'          => '',
				'amount'             => 5,
				'currency'           => 'USD',
			)
		);
	}

	/**
	 * The core promise: a site that works on 1.1.0 keeps working after the
	 * update, and stops storing the token in plaintext.
	 */
	public function test_upgrade_replaces_a_plaintext_token_with_its_hash(): void {
		$this->simulate_plaintext_token_install();

		Plugin::maybe_upgrade();

		$options = $this->stored_options();
		$this->assertSame( hash( 'sha256', 'legacy-token' ), $options['verification_token_sha256'] );
		$this->assertArrayNotHasKey( 'verification_token', $options, 'The plaintext token must be gone after the upgrade.' );
		$this->assertSame( 'subscriber', $options['default_role'], 'Other settings must survive the migration.' );
		$this->assertSame( 200, $this->donate( 'legacy-token' )->get_status(), 'The token Ko-fi already sends must still verify.' );
	}

	/**
	 * The fallback: a site whose options still hold plaintext although the
	 * schema version says it is current -- a restored backup, another code
	 * path, or a request that beat init -- verifies and migrates on the spot.
	 */
	public function test_the_webhook_verifies_and_migrates_a_plaintext_token_without_the_upgrade(): void {
		update_option( Plugin::DB_VERSION_OPTION, Plugin::DB_VERSION );
		$this->write_options_raw( $this->legacy_options() );

		$this->assertSame( 200, $this->donate( 'legacy-token' )->get_status() );

		$options = $this->stored_options();
		$this->assertSame( hash( 'sha256', 'legacy-token' ), $options['verification_token_sha256'] );
		$this->assertArrayNotHasKey( 'verification_token', $options );
	}

	/**
	 * A wrong token is still rejected on the fallback path, and a rejected
	 * request still migrates the site (the stored token is not in doubt).
	 */
	public function test_the_fallback_rejects_a_wrong_token(): void {
		update_option( Plugin::DB_VERSION_OPTION, Plugin::DB_VERSION );
		$this->write_options_raw( $this->legacy_options() );

		$this->assertSame( 401, $this->donate( 'not-the-token' )->get_status() );
		$this->assertSame( 200, $this->donate( 'legacy-token' )->get_status() );
	}

	/**
	 * If the migration's write fails, the donation must still go through and
	 * the plaintext must still be there -- nothing destroyed, nothing lost.
	 */
	public function test_a_failed_migration_write_neither_breaks_the_webhook_nor_loses_the_token(): void {
		update_option( Plugin::DB_VERSION_OPTION, Plugin::DB_VERSION );
		$this->write_options_raw( $this->legacy_options() );

		// Returning the old value makes update_option() write nothing.
		$refuse = static function ( $value, $old_value ) {
			unset( $value );
			return $old_value;
		};
		add_filter( 'pre_update_option_members_for_kofi_options', $refuse, 10, 2 );

		try {
			$this->assertSame( 200, $this->donate( 'legacy-token' )->get_status() );
			$this->assertFalse( VerificationToken::migrate() );
		} finally {
			remove_filter( 'pre_update_option_members_for_kofi_options', $refuse, 10 );
		}

		$this->assertSame( $this->legacy_options(), $this->stored_options() );
	}

	/**
	 * Running the upgrade again changes nothing.
	 */
	public function test_the_token_migration_is_idempotent(): void {
		$this->simulate_plaintext_token_install();

		Plugin::maybe_upgrade();
		$after_first = $this->stored_options();

		$this->assertFalse( VerificationToken::migrate(), 'A second migration must report no change.' );
		delete_option( Plugin::DB_VERSION_OPTION );
		Plugin::maybe_upgrade();

		$this->assertSame( $after_first, $this->stored_options() );
		$this->assertSame( 200, $this->donate( 'legacy-token' )->get_status(), 'A hash must never be hashed again.' );
	}

	/**
	 * Two requests racing: this one read the plaintext options, but by the time
	 * it migrates, an admin has saved a different token. The stale read must
	 * not put the old token back.
	 */
	public function test_a_stale_read_never_overwrites_a_newer_saved_token(): void {
		$stale = $this->legacy_options( 'old-token' );
		$this->write_options_raw(
			array( 'verification_token_sha256' => hash( 'sha256', 'new-token' ) )
			+ array_diff_key( $this->legacy_options(), array( 'verification_token' => true ) )
		);

		// What the stale request computes for its own check.
		VerificationToken::expected_hash( $stale );

		$this->assertSame( hash( 'sha256', 'new-token' ), $this->stored_options()['verification_token_sha256'] );
		$this->assertArrayNotHasKey( 'verification_token', $this->stored_options() );
	}

	/**
	 * The same race where the newer token is only in the database: another
	 * process saved it, and this request's in-memory option cache still holds
	 * the plaintext it loaded at start-up. migrate() must go back to the
	 * database before writing, or it would store the hash of the old token
	 * over the one the admin just saved.
	 */
	public function test_migration_rereads_past_a_stale_option_cache(): void {
		global $wpdb;

		$this->write_options_raw( $this->legacy_options( 'old-token' ) );
		get_option( 'members_for_kofi_options' ); // Warm the cache with the plaintext.

		$newer = array( 'verification_token_sha256' => hash( 'sha256', 'new-token' ) )
			+ array_diff_key( $this->legacy_options(), array( 'verification_token' => true ) );
		// Another process's write: straight to the table, cache untouched.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Simulating a concurrent request's write, which by definition bypasses this request's cache.
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $newer ) ), array( 'option_name' => 'members_for_kofi_options' ) );

		$this->assertSame( 'old-token', get_option( 'members_for_kofi_options' )['verification_token'], 'Fixture failed: the cache should still be stale.' );

		VerificationToken::migrate();

		$this->assertSame( $newer, $this->stored_options(), 'The newer token must survive.' );
	}

	/**
	 * An install that never configured a token has nothing to migrate: no
	 * bogus hash of an empty string, options untouched, webhooks rejected as
	 * before.
	 *
	 * @dataProvider unusable_tokens
	 *
	 * @param mixed $token Stored legacy token value.
	 */
	public function test_an_unusable_token_is_left_untouched( $token ): void {
		$this->simulate_plaintext_token_install( $token );
		$before = $this->stored_options();

		Plugin::maybe_upgrade();

		$this->assertSame( $before, $this->stored_options() );
		$this->assertArrayNotHasKey( 'verification_token_sha256', $this->stored_options() );
		$this->assertSame( 401, $this->donate( 'anything' )->get_status() );
		$this->assertSame( 401, $this->donate( hash( 'sha256', '' ) )->get_status() );
	}

	/**
	 * Stored token values that are not a usable token.
	 *
	 * @return array
	 */
	public function unusable_tokens(): array {
		return array(
			'empty string' => array( '' ),
			'array'        => array( array( 'x' ) ),
			'integer'      => array( 12345 ),
			'null'         => array( null ),
		);
	}

	/**
	 * A plaintext token that matches the stored hash is a redundant copy, and
	 * is removed.
	 */
	public function test_a_matching_plaintext_copy_is_removed(): void {
		update_option( Plugin::DB_VERSION_OPTION, '3' );
		$this->write_options_raw( array( 'verification_token_sha256' => hash( 'sha256', 'same' ) ) + $this->legacy_options( 'same' ) );

		Plugin::maybe_upgrade();

		$this->assertArrayNotHasKey( 'verification_token', $this->stored_options() );
		$this->assertSame( hash( 'sha256', 'same' ), $this->stored_options()['verification_token_sha256'] );
	}

	/**
	 * A plaintext token that does NOT match the hash cannot be judged: both are
	 * kept, the hash is what verifies, and the conflict is flagged.
	 */
	public function test_a_conflicting_plaintext_token_is_kept_and_flagged(): void {
		update_option( Plugin::DB_VERSION_OPTION, '3' );
		$conflict = array( 'verification_token_sha256' => hash( 'sha256', 'hashed' ) ) + $this->legacy_options( 'plain' );
		$this->write_options_raw( $conflict );

		Plugin::maybe_upgrade();

		$this->assertSame( $conflict, $this->stored_options() );
		$this->assertTrue( VerificationToken::has_conflict( $this->stored_options() ) );
		$this->assertSame( 200, $this->donate( 'hashed' )->get_status() );
		$this->assertSame( 401, $this->donate( 'plain' )->get_status() );
	}

	/**
	 * A fresh install never writes a plaintext token key, even an empty one.
	 */
	public function test_a_fresh_install_writes_no_token_key(): void {
		$this->write_options_raw( false );
		delete_option( Plugin::DB_VERSION_OPTION );

		Plugin::activate();

		$options = $this->stored_options();
		$this->assertIsArray( $options );
		$this->assertArrayNotHasKey( 'verification_token', $options );
		$this->assertArrayNotHasKey( 'verification_token_sha256', $options );
		$this->assertSame( 401, $this->donate( 'anything' )->get_status() );
	}

	/**
	 * Deactivate, update the files, reactivate: activation records the new
	 * schema version without maybe_upgrade() ever running, so it has to
	 * migrate the token itself.
	 */
	public function test_reactivation_migrates_a_plaintext_token(): void {
		$this->simulate_plaintext_token_install();

		Plugin::activate();

		$options = $this->stored_options();
		$this->assertSame( hash( 'sha256', 'legacy-token' ), $options['verification_token_sha256'] );
		$this->assertArrayNotHasKey( 'verification_token', $options );
		$this->assertSame( Plugin::DB_VERSION, get_option( Plugin::DB_VERSION_OPTION ) );
	}

	/**
	 * Uninstall removes the hash along with the rest of the options.
	 */
	public function test_uninstall_removes_the_token_hash(): void {
		$this->write_options_raw( array( 'verification_token_sha256' => hash( 'sha256', 'x' ) ) );

		Plugin::uninstall();

		$this->assertFalse( $this->stored_options() );
	}
}
