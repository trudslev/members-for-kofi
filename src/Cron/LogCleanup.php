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

		$table_name = esc_sql( $wpdb->prefix . 'members_for_kofi_user_logs' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix, which prepare() cannot parameterise; the cutoff value is a placeholder.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE timestamp < %s",
				self::get_cutoff_datetime( $retention_days )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

		$table_name = esc_sql( $wpdb->prefix . 'members_for_kofi_request_logs' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix, which prepare() cannot parameterise; the cutoff value is a placeholder.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE timestamp < %s",
				self::get_cutoff_datetime( $retention_days )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $deleted;
	}

	/**
	 * Builds the retention cutoff as a MySQL DATETIME string.
	 *
	 * The `timestamp` columns are DATETIME and rows are written with
	 * current_time( 'mysql' ), so the cutoff must be a local-time DATETIME
	 * string. Comparing those columns against a Unix integer makes MySQL
	 * coerce the column to a YYYYMMDDHHMMSS number, which is always larger
	 * than a Unix timestamp -- the predicate would never match.
	 *
	 * @param int $retention_days Number of days to keep logs.
	 * @return string Cutoff in 'Y-m-d H:i:s' local time.
	 */
	private static function get_cutoff_datetime( int $retention_days ): string {
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Must match current_time( 'mysql' ) used on write.
		$local_now = current_time( 'timestamp' );

		return gmdate( 'Y-m-d H:i:s', $local_now - ( $retention_days * DAY_IN_SECONDS ) );
	}
}
