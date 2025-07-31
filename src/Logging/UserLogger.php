<?php
/**
 * This file is part of the Ko-fi Members plugin.
 *
 * Ko-fi Members is free software: you can redistribute it and/or modify
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
 * @package KofiMembers
 */

namespace KofiMembers\Logging;

use wpdb;

/**
 * Handles logging events to the database and manages the logging table.
 */
class UserLogger {
	/**
	 * Logs a user action to the database.
	 *
	 * @param int         $user_id  The ID of the user.
	 * @param string      $email    The email of the user.
	 * @param string      $action   The action being logged (e.g., "Role assigned", "Donation received").
	 * @param string|null $role     The role associated with the action, if applicable.
	 * @param float|null  $amount   The donation amount, if applicable.
	 * @param string|null $currency The currency of the donation, if applicable.
	 */
	public function log_action( int $user_id, string $email, string $action, ?string $role = null, ?float $amount = null, ?string $currency = null ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'kofi_members_user_logs';

		$wpdb->insert(
			$table_name,
			array(
				'user_id'   => $user_id,
				'email'     => $email,
				'action'    => $action,
				'role'      => $role,
				'amount'    => $amount,
				'currency'  => $currency,
				'timestamp' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%f', '%s', '%s' )
		);
	}

	/**
	 * Logs a role assignment action.
	 *
	 * @param int    $user_id The ID of the user.
	 * @param string $email   The email of the user.
	 * @param string $role    The role assigned to the user.
	 */
	public function log_role_assignment( int $user_id, string $email, string $role ): void {
		$this->log_action( $user_id, $email, 'Role assigned', $role );
	}

	/**
	 * Logs a role removal action.
	 *
	 * @param int    $user_id The ID of the user.
	 * @param string $email   The email of the user.
	 * @param string $role    The role removed from the user.
	 */
	public function log_role_removal( int $user_id, string $email, string $role ): void {
		$this->log_action( $user_id, $email, 'Role removed', $role );
	}

	/**
	 * Logs a donation action.
	 *
	 * @param int    $user_id  The ID of the user.
	 * @param string $email    The email of the user.
	 * @param float  $amount   The donation amount.
	 * @param string $currency The currency of the donation.
	 */
	public function log_donation( int $user_id, string $email, float $amount, string $currency ): void {
		$this->log_action( $user_id, $email, 'Donation received', null, $amount, $currency );
	}

	/**
	 * Generates the SQL statement for creating the logging table.
	 *
	 * @return string The SQL statement for creating the table.
	 */
	public static function get_create_table_sql(): string {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'kofi_members_user_logs';
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE `$table_name` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` BIGINT(20) UNSIGNED NOT NULL,
			`email` VARCHAR(255) NOT NULL,
			`action` VARCHAR(50) NOT NULL,
			`role` VARCHAR(50) DEFAULT NULL,
			`amount` DECIMAL(10,2) DEFAULT NULL,
			`currency` VARCHAR(10) DEFAULT NULL,
			`timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (`id`),
			KEY `user_id` (`user_id`),
			KEY `action` (`action`),
			KEY `timestamp` (`timestamp`)
		) $charset_collate;";
	}

	/**
	 * Creates or updates the logging table in the database.
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
	 * Deletes the logging table from the database.
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'kofi_members_user_logs';
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', $table_name ) );
	}
}
