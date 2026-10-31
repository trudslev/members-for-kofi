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
 * Public functions for other plugins and themes.
 *
 * @package MembersForKofi
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'members_for_kofi_get_membership' ) ) {
	/**
	 * Reports a user's Ko-fi membership.
	 *
	 * Check for the function before calling it, since this plugin may be
	 * inactive: `if ( function_exists( 'members_for_kofi_get_membership' ) )`.
	 *
	 * @since 1.4.0
	 *
	 * @param int $user_id User ID.
	 * @return array|null Null when this plugin never assigned the user a role.
	 *                    Otherwise an array with:
	 *                    - status          (string)   'active' or 'none'
	 *                    - role            (string)   Role slug
	 *                    - managed         (bool)     False when the user held the
	 *                                                 role before paying for it, so
	 *                                                 the plugin will not remove it
	 *                    - subscription    (bool|null) Last payment was a
	 *                                                 subscription; null if unknown
	 *                    - last_payment_at (int|null) Unix time of the last payment
	 *                    - expires_at      (int|null) Unix time access lapses or
	 *                                                 lapsed; null if expiry is off
	 *                    - manage_url      (string)   Where to manage Ko-fi
	 *                    - cancel_url      (string)   Where to cancel
	 *                    - cancel_hint     (string)   How to cancel, in words
	 *                    The whole array passes through the filter of the same
	 *                    name, `members_for_kofi_get_membership`.
	 */
	function members_for_kofi_get_membership( int $user_id ): ?array {
		return \MembersForKofi\Membership::get( $user_id );
	}
}
