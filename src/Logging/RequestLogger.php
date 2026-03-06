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
	 * Logs a webhook request to the database.
	 *
	 * @param array  $payload      The webhook payload data.
	 * @param int    $status_code  HTTP status code of the response.
	 * @param bool   $success      Whether the request was processed successfully.
	 * @param string $error        Error message if request failed.
	 */
	public function log_request( array $payload, int $status_code, bool $success, string $error = '' ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';

		// Extract key fields from payload for easier querying.
		$email              = sanitize_email( $payload['email'] ?? '' );
		$tier_name          = sanitize_text_field( $payload['tier_name'] ?? '' );
		$amount             = floatval( $payload['amount'] ?? 0 );
		$currency           = sanitize_text_field( $payload['currency'] ?? '' );
		$is_subscription    = ! empty( $payload['is_subscription_payment'] );
		$verification_token = ! empty( $payload['verification_token'] ) ? substr( $payload['verification_token'], 0, 10 ) . '...' : '';

		// Redact sensitive data from payload before storing as JSON.
		$payload_sanitized = $payload;
		if ( isset( $payload_sanitized['verification_token'] ) ) {
			$payload_sanitized['verification_token'] = '[REDACTED]';
		}
		$payload_json = wp_json_encode( $payload_sanitized );

		$wpdb->insert(
			$table_name,
			array(
				'email'              => $email,
				'tier_name'          => $tier_name,
				'amount'             => $amount,
				'currency'           => $currency,
				'is_subscription'    => $is_subscription,
				'verification_token' => $verification_token,
				'payload'            => $payload_json,
				'status_code'        => $status_code,
				'success'            => $success,
				'error'              => $error,
				'timestamp'          => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
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
			`verification_token` VARCHAR(20) DEFAULT NULL,
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

		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table drop is intentional during uninstall.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' );
	}
}
