<?php
/**
 * LogCleanup Tests
 *
 * @package MembersForKofi
 * @subpackage Tests
 */

namespace MembersForKofi\Tests\Cron;

use MembersForKofi\Cron\LogCleanup;
use WP_UnitTestCase;

/**
 * Test case for LogCleanup cron job.
 */
class LogCleanupTest extends WP_UnitTestCase {
	/**
	 * User logs table name.
	 *
	 * @var string
	 */
	private $user_logs_table;

	/**
	 * Request logs table name.
	 *
	 * @var string
	 */
	private $request_logs_table;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->user_logs_table    = $wpdb->prefix . 'members_for_kofi_user_logs';
		$this->request_logs_table = $wpdb->prefix . 'members_for_kofi_request_logs';

		// Ensure tables exist.
		$this->create_test_tables();

		// Clear any existing test data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$this->user_logs_table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$this->request_logs_table}" );
	}

	/**
	 * Creates test tables if they don't exist.
	 */
	private function create_test_tables(): void {
		global $wpdb;

		// Create user logs table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$this->user_logs_table} (
				id INT AUTO_INCREMENT PRIMARY KEY,
				user_id BIGINT(20) NOT NULL,
				email VARCHAR(100) NOT NULL,
				action VARCHAR(50) NOT NULL,
				role VARCHAR(50) NOT NULL,
				amount VARCHAR(20) DEFAULT NULL,
				currency VARCHAR(10) DEFAULT NULL,
				timestamp INT NOT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
		);

		// Create request logs table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$this->request_logs_table} (
				id INT AUTO_INCREMENT PRIMARY KEY,
				email VARCHAR(100) NOT NULL,
				tier_name VARCHAR(100) DEFAULT NULL,
				amount VARCHAR(20) DEFAULT NULL,
				currency VARCHAR(10) DEFAULT NULL,
				is_subscription TINYINT(1) DEFAULT 0,
				verification_token VARCHAR(255) DEFAULT NULL,
				payload TEXT DEFAULT NULL,
				status_code INT DEFAULT NULL,
				success TINYINT(1) DEFAULT 0,
				error TEXT DEFAULT NULL,
				timestamp INT NOT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
		);
	}

	/**
	 * Test that cleanup respects the auto_clear_logs setting.
	 */
	public function test_cleanup_respects_auto_clear_setting(): void {
		// Disable auto cleanup.
		update_option(
			'members_for_kofi_options',
			array(
				'auto_clear_logs'    => false,
				'log_retention_days' => 30,
			)
		);

		// Add old log entries.
		$this->insert_user_log( 40 );
		$this->insert_request_log( 40 );

		// Execute cleanup.
		$log_cleanup = new LogCleanup();
		$log_cleanup->execute();

		// Verify logs were not deleted.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$user_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->user_logs_table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$request_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->request_logs_table}" );

		$this->assertEquals( 1, $user_count, 'User logs should not be deleted when auto_clear_logs is false' );
		$this->assertEquals( 1, $request_count, 'Request logs should not be deleted when auto_clear_logs is false' );
	}

	/**
	 * Test that cleanup deletes old user logs.
	 */
	public function test_cleanup_deletes_old_user_logs(): void {
		// Enable auto cleanup with 30-day retention.
		update_option(
			'members_for_kofi_options',
			array(
				'auto_clear_logs'    => true,
				'log_retention_days' => 30,
			)
		);

		// Add old and recent logs.
		$this->insert_user_log( 40 ); // Old (should be deleted).
		$this->insert_user_log( 20 ); // Recent (should be kept).
		$this->insert_user_log( 10 ); // Recent (should be kept).

		// Execute cleanup.
		$log_cleanup = new LogCleanup();
		$deleted     = $log_cleanup->delete_old_user_logs( 30 );

		$this->assertEquals( 1, $deleted, 'Should delete 1 old log entry' );

		// Verify only recent logs remain.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$remaining_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->user_logs_table}" );
		$this->assertEquals( 2, $remaining_count, 'Should have 2 recent logs remaining' );
	}

	/**
	 * Test that cleanup deletes old request logs.
	 */
	public function test_cleanup_deletes_old_request_logs(): void {
		// Enable auto cleanup with 30-day retention.
		update_option(
			'members_for_kofi_options',
			array(
				'auto_clear_logs'    => true,
				'log_retention_days' => 30,
			)
		);

		// Add old and recent logs.
		$this->insert_request_log( 40 ); // Old (should be deleted).
		$this->insert_request_log( 20 ); // Recent (should be kept).
		$this->insert_request_log( 10 ); // Recent (should be kept).

		// Execute cleanup.
		$log_cleanup = new LogCleanup();
		$deleted     = $log_cleanup->delete_old_request_logs( 30 );

		$this->assertEquals( 1, $deleted, 'Should delete 1 old log entry' );

		// Verify only recent logs remain.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$remaining_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->request_logs_table}" );
		$this->assertEquals( 2, $remaining_count, 'Should have 2 recent logs remaining' );
	}

	/**
	 * Test that cleanup handles empty tables gracefully.
	 */
	public function test_cleanup_handles_empty_tables(): void {
		// Enable auto cleanup.
		update_option(
			'members_for_kofi_options',
			array(
				'auto_clear_logs'    => true,
				'log_retention_days' => 30,
			)
		);

		// Execute cleanup on empty tables.
		$log_cleanup = new LogCleanup();
		$deleted     = $log_cleanup->delete_old_user_logs( 30 );

		$this->assertEquals( 0, $deleted, 'Should delete 0 entries from empty table' );

		$deleted = $log_cleanup->delete_old_request_logs( 30 );
		$this->assertEquals( 0, $deleted, 'Should delete 0 entries from empty table' );
	}

	/**
	 * Test that cleanup keeps recent logs.
	 */
	public function test_cleanup_keeps_recent_logs(): void {
		// Enable auto cleanup with 30-day retention.
		update_option(
			'members_for_kofi_options',
			array(
				'auto_clear_logs'    => true,
				'log_retention_days' => 30,
			)
		);

		// Add only recent logs.
		$this->insert_user_log( 10 );
		$this->insert_user_log( 5 );
		$this->insert_request_log( 10 );
		$this->insert_request_log( 5 );

		// Execute cleanup.
		$log_cleanup = new LogCleanup();
		$log_cleanup->execute();

		// Verify all logs remain.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$user_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->user_logs_table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$request_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->request_logs_table}" );

		$this->assertEquals( 2, $user_count, 'Should keep all recent user logs' );
		$this->assertEquals( 2, $request_count, 'Should keep all recent request logs' );
	}

	/**
	 * Test full cleanup execution with both tables.
	 */
	public function test_full_cleanup_execution(): void {
		// Enable auto cleanup with 30-day retention.
		update_option(
			'members_for_kofi_options',
			array(
				'auto_clear_logs'    => true,
				'log_retention_days' => 30,
			)
		);

		// Add mix of old and recent logs to both tables.
		$this->insert_user_log( 50 );
		$this->insert_user_log( 40 );
		$this->insert_user_log( 20 );
		$this->insert_request_log( 50 );
		$this->insert_request_log( 40 );
		$this->insert_request_log( 20 );

		// Execute full cleanup.
		$log_cleanup = new LogCleanup();
		$log_cleanup->execute();

		// Verify correct logs remain.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$user_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->user_logs_table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$request_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->request_logs_table}" );

		$this->assertEquals( 1, $user_count, 'Should keep 1 recent user log' );
		$this->assertEquals( 1, $request_count, 'Should keep 1 recent request log' );
	}

	/**
	 * Helper method to insert a user log entry.
	 *
	 * @param int $days_ago Number of days in the past.
	 */
	private function insert_user_log( int $days_ago ): void {
		global $wpdb;

		$timestamp = time() - ( $days_ago * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->user_logs_table,
			array(
				'user_id'   => 1,
				'email'     => 'test@example.com',
				'action'    => 'role_assigned',
				'role'      => 'subscriber',
				'amount'    => '5.00',
				'currency'  => 'USD',
				'timestamp' => $timestamp,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Helper method to insert a request log entry.
	 *
	 * @param int $days_ago Number of days in the past.
	 */
	private function insert_request_log( int $days_ago ): void {
		global $wpdb;

		$timestamp = time() - ( $days_ago * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->request_logs_table,
			array(
				'email'              => 'test@example.com',
				'tier_name'          => 'Gold',
				'amount'             => '5.00',
				'currency'           => 'USD',
				'is_subscription'    => 1,
				'verification_token' => 'test_token',
				'payload'            => '{}',
				'status_code'        => 200,
				'success'            => 1,
				'error'              => null,
				'timestamp'          => $timestamp,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%d' )
		);
	}
}
