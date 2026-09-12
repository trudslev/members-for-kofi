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
 */

namespace MembersForKofi\Logging;

defined( 'ABSPATH' ) || exit;

use wpdb;

/**
 * Handles logging webhook requests to the database.
 */
class RequestLogger {

	/**
	 * Longest payload JSON kept in the log, in bytes.
	 *
	 * The column is TEXT (~64 KB). Without a cap, anything that reaches the
	 * logger can decide how much of the database it occupies per request.
	 *
	 * @var int
	 */
	public const MAX_PAYLOAD_LENGTH = 4096;
	/**
	 * Logs a webhook request to the database.
	 *
	 * @param array  $payload      The webhook payload data.
	 * @param int    $status_code  HTTP status code of the response.
	 * @param bool   $success      Whether the request was processed successfully.
	 * @param string $error        Error message if request failed.
	 */
	public function log_request( array $payload, int $status_code, bool $success, string $error = '' ): void {
		global $wpdb;

		$table_name = esc_sql( $wpdb->prefix . 'members_for_kofi_request_logs' );

		// Extract key fields from payload for easier querying.
		$email           = sanitize_email( $payload['email'] ?? '' );
		$tier_name       = sanitize_text_field( $payload['tier_name'] ?? '' );
		$amount          = floatval( $payload['amount'] ?? 0 );
		$currency        = sanitize_text_field( $payload['currency'] ?? '' );
		$is_subscription = ! empty( $payload['is_subscription_payment'] );

		// Redact sensitive data from payload before storing as JSON.
		$payload_sanitized = $payload;
		if ( isset( $payload_sanitized['verification_token'] ) ) {
			$payload_sanitized['verification_token'] = '[REDACTED]';
		}
		$payload_json = (string) wp_json_encode( $payload_sanitized );

		if ( strlen( $payload_json ) > self::MAX_PAYLOAD_LENGTH ) {
			// Truncation makes this not-JSON, which is fine: the column is a
			// diagnostic record, and a marker is more honest than silently
			// storing a prefix that looks parseable but is not.
			$payload_json = substr( $payload_json, 0, self::MAX_PAYLOAD_LENGTH ) . '...[truncated]';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writing to the plugin's own table; core offers no API for custom tables.
		$wpdb->insert(
			$table_name,
			array(
				'email'           => $email,
				'tier_name'       => $tier_name,
				'amount'          => $amount,
				'currency'        => $currency,
				'is_subscription' => $is_subscription,
				'payload'         => $payload_json,
				'status_code'     => $status_code,
				'success'         => $success,
				'error'           => $error,
				'timestamp'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%f', '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		// Invalidate cached total logs count so UI refresh reflects new entries.
		delete_transient( 'members_for_kofi_total_request_logs' );
	}

	/**
	 * Generates the SQL statement for creating the request logs table.
	 *
	 * @return string The SQL statement for creating the table.
	 */
	public static function get_create_table_sql(): string {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'members_for_kofi_request_logs';
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE `$table_name` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`email` VARCHAR(255) DEFAULT NULL,
			`tier_name` VARCHAR(100) DEFAULT NULL,
			`amount` DECIMAL(10,2) DEFAULT NULL,
			`currency` VARCHAR(10) DEFAULT NULL,
			`is_subscription` TINYINT(1) DEFAULT 0,
			`payload` TEXT NOT NULL,
			`status_code` INT(3) NOT NULL,
			`success` TINYINT(1) NOT NULL DEFAULT 0,
			`error` TEXT DEFAULT NULL,
			`timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (`id`),
			KEY `email` (`email`),
			KEY `success` (`success`),
			KEY `status_code` (`status_code`),
			KEY `timestamp` (`timestamp`)
		) $charset_collate;";
	}

	/**
	 * Removes the legacy verification_token column and everything it held.
	 *
	 * The 1.1.x development line stored the first ten characters of the site's
	 * real Ko-fi verification token on every request. It was never displayed and
	 * never queried -- just a fragment of a live secret sitting at rest. The
	 * column is dropped rather than blanked so any historic values go with it.
	 * No public release shipped it, but a site running unreleased code may have
	 * the column, so the migration has to handle it.
	 *
	 * dbDelta() only ever adds columns, so this has to be an explicit ALTER.
	 *
	 * @return void
	 */
	public static function drop_verification_token_column(): void {
		global $wpdb;

		$table_name = esc_sql( $wpdb->prefix . 'members_for_kofi_request_logs' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration on the plugin's own table; the name comes from $wpdb->prefix.
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$table_name}` LIKE %s", 'verification_token' )
		);

		if ( $exists ) {
			$wpdb->query( "ALTER TABLE `{$table_name}` DROP COLUMN `verification_token`" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Creates or updates the request logs table in the database.
	 */
	public static function create_table(): void {
		global $wpdb;

		// Get the SQL statement for creating the table.
		$sql = self::get_create_table_sql();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Use dbDelta to create or update the table.
		dbDelta( $sql );
	}

	/**
	 * Deletes the request logs table from the database.
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table_name = esc_sql( $wpdb->prefix . 'members_for_kofi_request_logs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table during uninstall.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' );
	}
}
