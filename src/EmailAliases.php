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

namespace MembersForKofi;

defined( 'ABSPATH' ) || exit;

use MembersForKofi\Webhook\Webhook;

/**
 * Remembers the addresses a member used before changing their email.
 *
 * Ko-fi identifies a supporter only by email, and keeps sending the address
 * they gave Ko-fi. Without this, a member who changed their email on the site
 * got a second account on their next payment, and the real account's role
 * expired.
 *
 * @since 1.5.0
 */
class EmailAliases {

	/**
	 * User meta holding one previous address per row.
	 *
	 * @var string
	 */
	public const META = 'kofi_email_alias';

	/**
	 * Hooks the recording of email changes.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'profile_update', array( self::class, 'record_change' ), 10, 2 );
	}

	/**
	 * Keeps the old address when a Ko-fi member changes their email.
	 *
	 * Only for users this plugin knows: everyone else's old addresses are none
	 * of its business.
	 *
	 * @param int           $user_id       User ID.
	 * @param \WP_User|null $old_user_data The user before the update.
	 * @return void
	 */
	public static function record_change( $user_id, $old_user_data = null ): void {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user || ! $old_user_data instanceof \WP_User ) {
			return;
		}

		$old = self::normalise( (string) $old_user_data->user_email );
		$new = self::normalise( (string) $user->user_email );
		if ( '' === $old || $old === $new || ! self::is_known( $user_id ) ) {
			return;
		}

		$aliases = self::of( $user_id );

		// Changing back to an earlier address makes it current again.
		if ( in_array( $new, $aliases, true ) ) {
			delete_user_meta( $user_id, self::META, $new );
		}

		if ( ! in_array( $old, $aliases, true ) ) {
			add_user_meta( $user_id, self::META, $old );
		}
	}

	/**
	 * The user a Ko-fi email belongs to.
	 *
	 * The current address always wins. Failing that, the member who most
	 * recently gave up that address.
	 *
	 * @param string $email Email address.
	 * @return \WP_User|null
	 */
	public static function find_user( string $email ): ?\WP_User {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return $user;
		}

		$owners = self::owners( $email );
		if ( ! $owners ) {
			return null;
		}

		$user = get_userdata( $owners[0] );
		return $user ? $user : null;
	}

	/**
	 * Users holding an address as a previous one, most recent first.
	 *
	 * @param string $email   Email address.
	 * @param int    $exclude User ID to leave out.
	 * @return int[]
	 */
	public static function owners( string $email, int $exclude = 0 ): array {
		global $wpdb;

		$email = self::normalise( $email );
		if ( '' === $email ) {
			return array();
		}

		// A direct query on meta_key (indexed): a meta_query through
		// WP_User_Query joins usermeta, which upgrade code has learned to avoid.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup on the indexed meta_key; caching would hide a change made a moment ago.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s AND user_id <> %d ORDER BY umeta_id DESC LIMIT 10",
				self::META,
				$email,
				$exclude
			)
		);

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * A user's previous addresses.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function of( int $user_id ): array {
		return array_values( array_filter( array_map( 'strval', (array) get_user_meta( $user_id, self::META, false ) ) ) );
	}

	/**
	 * Whether the plugin has a reason to know this user.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function is_known( int $user_id ): bool {
		return null !== Membership::get( $user_id )
			|| '' !== (string) get_user_meta( $user_id, Webhook::CREATED_META, true );
	}

	/**
	 * Addresses compared the way MySQL and Ko-fi treat them: case aside.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private static function normalise( string $email ): string {
		return strtolower( trim( sanitize_email( $email ) ) );
	}
}
