<?php
/**
 * Base test case for tests that need a real WordPress environment.
 *
 * @package MembersForKofi
 * @subpackage Tests
 */

namespace MembersForKofi\Tests;

use MembersForKofi\Logging\RequestLogger;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Webhook\Webhook;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Provides the per-test isolation that WP_UnitTestCase used to give us.
 *
 * WordPress is booted once for the whole run (see bootstrap.php), so state has
 * to be reset explicitly between tests: the plugin's own tables are recreated
 * and emptied, its option is cleared, and any users a test created are removed.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Highest user ID that existed before the current test started.
	 *
	 * @var int
	 */
	private int $user_id_watermark = 0;

	/**
	 * The site's plugin options as they were before the current test.
	 *
	 * @var mixed
	 */
	private $original_options = false;

	/**
	 * Prepares a clean slate before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		UserLogger::create_table();
		RequestLogger::create_table();

		$this->truncate_log_tables();

		$this->original_options = get_option( 'members_for_kofi_options', false );
		delete_option( 'members_for_kofi_options' );

		$this->user_id_watermark = $this->max_user_id();

		// The webhook sheds requests from an address that keeps failing. Tests
		// deliberately send bad tokens, so without this the failures accumulate
		// across the suite and later tests start seeing 429s depending on the
		// order they happen to run in.
		Webhook::reset_failure_count();
	}

	/**
	 * Puts the site back exactly as the test found it.
	 *
	 * The options are restored rather than deleted: this WordPress install is
	 * shared with the HTTP integration suite, and leaving it unconfigured would
	 * make every subsequent donation request fail authentication.
	 */
	protected function tearDown(): void {
		$this->delete_users_created_during_test();
		$this->truncate_log_tables();

		// Raw, so the restore is not rewritten by sanitize_options(): that would
		// keep this test's token hash instead of the site's, and the HTTP suite
		// sharing this install would then fail to authenticate.
		$this->write_options_raw( $this->original_options );

		parent::tearDown();
	}

	/**
	 * Writes the plugin options exactly as given, bypassing sanitize_options().
	 *
	 * Once any test has called AdminSettings::register_settings(), every
	 * update_option() on the plugin option runs through the settings sanitizer
	 * for the rest of the run, which hashes a plaintext token on the way in.
	 * Fixtures that must look like an older install -- a plaintext token, an
	 * empty one, a conflicting pair -- have to bypass it, or the upgrade tests
	 * would be exercising data the sanitizer had already migrated.
	 *
	 * @param mixed $value Options to store, or false to delete the option.
	 * @return void
	 */
	protected function write_options_raw( $value ): void {
		global $wp_filter;

		$hook  = 'sanitize_option_members_for_kofi_options';
		$saved = $wp_filter[ $hook ] ?? null;
		unset( $wp_filter[ $hook ] );

		try {
			if ( false === $value ) {
				delete_option( 'members_for_kofi_options' );
			} else {
				update_option( 'members_for_kofi_options', $value );
			}
		} finally {
			if ( null !== $saved ) {
				$wp_filter[ $hook ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring exactly what was removed above.
			}
		}
	}

	/**
	 * Creates a WordPress user, replacing WP_UnitTestCase's user factory.
	 *
	 * @param array $args Optional overrides accepted by wp_insert_user().
	 * @return int The new user ID.
	 */
	protected function create_user( array $args = array() ): int {
		$suffix = wp_generate_password( 8, false, false );

		$user_id = wp_insert_user(
			array_merge(
				array(
					'user_login' => 'kofi_test_' . $suffix,
					'user_email' => 'kofi_test_' . $suffix . '@example.com',
					'user_pass'  => wp_generate_password(),
					'role'       => 'subscriber',
				),
				$args
			)
		);

		$this->assertNotWPError( $user_id, 'Failed to create test user.' );

		return (int) $user_id;
	}

	/**
	 * Asserts a value is not a WP_Error, surfacing the message when it is.
	 *
	 * @param mixed  $actual  Value to check.
	 * @param string $message Assertion message.
	 * @return void
	 */
	protected function assertNotWPError( $actual, string $message = '' ): void {
		if ( is_wp_error( $actual ) ) {
			$this->fail( trim( $message . ' ' . $actual->get_error_message() ) );
		}

		$this->assertTrue( true );
	}

	/**
	 * Returns the plugin's log table names.
	 *
	 * @return array<string>
	 */
	protected function log_tables(): array {
		global $wpdb;

		return array(
			$wpdb->prefix . 'members_for_kofi_user_logs',
			$wpdb->prefix . 'members_for_kofi_request_logs',
		);
	}

	/**
	 * Empties both plugin log tables.
	 */
	protected function truncate_log_tables(): void {
		global $wpdb;

		foreach ( $this->log_tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, name built from $wpdb->prefix.
			$wpdb->query( 'TRUNCATE TABLE `' . esc_sql( $table ) . '`' );
		}
	}

	/**
	 * Highest existing user ID.
	 *
	 * @return int
	 */
	private function max_user_id(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Core table, test-only bookkeeping.
		return (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" );
	}

	/**
	 * Deletes users created since setUp() ran.
	 */
	private function delete_users_created_during_test(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Core table, test-only cleanup.
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d", $this->user_id_watermark )
		);

		foreach ( $ids as $id ) {
			wp_delete_user( (int) $id );
		}
	}
}
