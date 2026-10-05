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

defined( 'ABSPATH' ) || exit;

use MembersForKofi\Logging\DebugLogger;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Webhook\Webhook;

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
	 * Checks for expired roles and removes them.
	 */
	public function check_and_remove_expired_roles(): void {
		global $wpdb;

		$expiration_meta_key = 'kofi_role_assigned_at';
		$options             = get_option( 'members_for_kofi_options' );
		$expiry_days         = $options['role_expiry_days'] ?? 35;

		// The settings screen offers an "Enable Expiry" toggle, and until now
		// nothing read it: turning expiry off still removed roles after the
		// configured number of days.
		//
		// A missing key means an install from before the toggle existed, where
		// expiry has always run -- so absent keeps meaning enabled, and only an
		// explicit opt-out disables it. Returning here also skips the user query
		// entirely rather than looping and removing nothing.
		if ( empty( $options['enable_expiry'] ?? true ) ) {
			DebugLogger::info( 'Role expiry is disabled; nothing to do' );
			return;
		}

		$users = get_users(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once a day in cron, never on a page load.
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
				// A renewal may have landed since the list was read. Read the
				// timestamp again, past the cache, so a member who just paid is
				// not stripped for a month until their next payment.
				wp_cache_delete( $user->ID, 'user_meta' );
				$fresh = (int) get_user_meta( $user->ID, $expiration_meta_key, true );
				if ( ! $fresh || time() <= strtotime( "+$expiry_days days", $fresh ) ) {
					continue;
				}

				$roles_to_remove = get_user_meta( $user->ID, 'kofi_donation_assigned_role', true );
				$preexisting     = (string) get_user_meta( $user->ID, Webhook::PREEXISTING_ROLE_META, true );

				if ( $roles_to_remove ) {
					foreach ( (array) $roles_to_remove as $role ) {
						// Someone else granted this role before the donor paid for
						// it: stop tracking it, but it is not the plugin's to take.
						if ( $preexisting === $role ) {
							continue;
						}

						// Use the global namespace for WP_User.
						$wp_user = new \WP_User( $user->ID );
						$wp_user->remove_role( $role );

						// Log role removal using the injected UserLogger.
						$this->user_logger->log_role_removal( $user->ID, $user->user_email, $role );
					}

					// Clean up metadata.
					delete_user_meta( $user->ID, $expiration_meta_key );
					delete_user_meta( $user->ID, 'kofi_donation_assigned_role' );
					delete_user_meta( $user->ID, Webhook::PREEXISTING_ROLE_META );
				}
			}
		}
	}
}
