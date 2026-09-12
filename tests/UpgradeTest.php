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
use MembersForKofi\Plugin;

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
}
