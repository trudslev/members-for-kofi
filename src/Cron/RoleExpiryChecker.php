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

namespace MembersForKofi\Cron;

use MembersForKofi\Logging\UserLogger;

/**
 * Class RoleExpiryChecker
 *
 * Handles the scheduling and execution of role expiry checks for Members for Ko-fi.
 *
 * @package MembersForKofi\Cron
 */
class RoleExpiryChecker {
	/**
	 * Logger instance for logging user role changes.
	 *
	 * @var UserLogger
	 */
	private UserLogger $user_logger;

	/**
	 * Constructor for RoleExpiryChecker.
	 *
	 * @param UserLogger $user_logger The logger instance to use for logging role removals.
	 */
	public function __construct( UserLogger $user_logger ) {
		$this->user_logger = $user_logger;
	}

	/**
	 * Schedules the daily role expiry check event.
	 *
	 * This function ensures that the 'kofi_members_check_role_expiry' event
	 * is scheduled to run daily. If the event is not already scheduled, it
	 * will be added to the WordPress cron system.
	 */
	public static function schedule(): void {
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( 'kofi_members_check_role_expiry' ) ) {
					wp_schedule_event( time(), 'daily', 'kofi_members_check_role_expiry' );
				}
			}
		);

		add_action( 'kofi_members_check_role_expiry', array( self::class, 'remove_expired_roles' ) );
	}

	/**
	 * Removes expired roles from users.
	 *
	 * This function checks all users for roles assigned via Ko-fi donations
	 * that have expired based on the configured expiry days. It removes the
	 * expired roles and associated metadata.
	 */
	public static function remove_expired_roles(): void {
		$options     = get_option( 'kofi_members_options', array() );
		$expiry_days = absint( $options['role_expiry_days'] ?? 35 );
		$cutoff      = time() - ( $expiry_days * DAY_IN_SECONDS );

		$users = get_users(
			array(
				'meta_key'     => 'kofi_role_assigned_at',
				'meta_compare' => '<',
				'meta_value'   => $cutoff,
				'number'       => -1,
				'fields'       => array( 'ID' ),
			)
		);

		foreach ( $users as $user_obj ) {
			$user          = new \WP_User( $user_obj->ID );
			$assigned_role = get_user_meta( $user->ID, 'kofi_donation_assigned_role', true );

			if ( $assigned_role && in_array( $assigned_role, $user->roles ) ) {
				$user->remove_role( $assigned_role );
				delete_user_meta( $user->ID, 'kofi_donation_assigned_role' );
				delete_user_meta( $user->ID, 'kofi_donation_amount' );
				delete_user_meta( $user->ID, 'kofi_role_assigned_at' );
			}
		}
	}

	/**
	 * Checks for expired roles and removes them.
	 */
	public function check_and_remove_expired_roles(): void {
		global $wpdb;

		$expiration_meta_key = 'kofi_role_assigned_at';
		$options             = get_option( 'kofi_members_options' );
		$expiry_days         = $options['role_expiry_days'] ?? 35;

		$users = get_users(
			array(
				'meta_key'     => $expiration_meta_key,
				'meta_compare' => 'EXISTS',
			)
		);

		foreach ( $users as $user ) {
			$assigned_at = get_user_meta( $user->ID, $expiration_meta_key, true );

			if ( ! $assigned_at ) {
				continue;
			}

			$assigned_time = (int) $assigned_at;
			$expiry_time   = strtotime( "+$expiry_days days", $assigned_time );

			if ( time() > $expiry_time ) {
				$roles_to_remove = get_user_meta( $user->ID, 'kofi_donation_assigned_role', true );

				if ( $roles_to_remove ) {
					foreach ( (array) $roles_to_remove as $role ) {
						// Use the global namespace for WP_User.
						$wp_user = new \WP_User( $user->ID );
						$wp_user->remove_role( $role );

						// Log role removal using the injected UserLogger.
						$this->user_logger->log_role_removal( $user->ID, $user->user_email, $role );
					}

					// Clean up metadata.
					delete_user_meta( $user->ID, $expiration_meta_key );
					delete_user_meta( $user->ID, 'kofi_donation_assigned_role' );
				}
			}
		}
	}
}
