<?php
/**
 * Log Cleanup Cron Job
 *
 * Handles automatic deletion of old log entries based on retention settings.
 *
 * @package MembersForKofi
 * @since 1.0.2
 * @license GPL-3.0-or-later
 */

namespace MembersForKofi\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * LogCleanup class
 *
 * Executes daily cleanup of user logs and request logs based on configured
 * retention period.
 */
class LogCleanup {
	/**
	 * Executes the log cleanup process.
	 *
	 * Checks if automatic cleanup is enabled, then deletes old logs from both
	 * the user logs and request logs tables based on the retention period.
	 *
	 * @return void
	 */
	public function execute(): void {
		$options = get_option( 'members_for_kofi_options', array() );

		// Check if auto cleanup is enabled (default: true).
		$auto_clear_logs = isset( $options['auto_clear_logs'] ) ? (bool) $options['auto_clear_logs'] : true;

		if ( ! $auto_clear_logs ) {
			return;
		}

		// Get retention period (default: 30 days).
		$log_retention_days = isset( $options['log_retention_days'] ) ? absint( $options['log_retention_days'] ) : 30;

		// Execute cleanup for both log tables.
		$this->delete_old_user_logs( $log_retention_days );
		$this->delete_old_request_logs( $log_retention_days );
	}

	/**
	 * Deletes old user logs beyond the retention period.
	 *
	 * @param int $retention_days Number of days to keep logs.
	 * @return int Number of rows deleted.
	 */
	public function delete_old_user_logs( int $retention_days ): int {
		global $wpdb;

		$table_name       = $wpdb->prefix . 'members_for_kofi_user_logs';
		$cutoff_timestamp = time() - ( $retention_days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE timestamp < %d",
				$cutoff_timestamp
			)
		);

		return (int) $deleted;
	}

	/**
	 * Deletes old request logs beyond the retention period.
	 *
	 * @param int $retention_days Number of days to keep logs.
	 * @return int Number of rows deleted.
	 */
	public function delete_old_request_logs( int $retention_days ): int {
		global $wpdb;

		$table_name       = $wpdb->prefix . 'members_for_kofi_request_logs';
		$cutoff_timestamp = time() - ( $retention_days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE timestamp < %d",
				$cutoff_timestamp
			)
		);

		return (int) $deleted;
	}
}
