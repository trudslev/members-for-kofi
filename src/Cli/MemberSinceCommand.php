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

namespace MembersForKofi\Cli;

defined( 'ABSPATH' ) || exit;

use MembersForKofi\Membership;

/**
 * Reads and sets when a supporter became a member.
 *
 * The plugin records it from the first payment it sees. Memberships that began
 * earlier -- before 1.4.0, or under another plugin -- can be given their real
 * date here, for example from the SinceDateUTC column of Ko-fi's supporter
 * export.
 *
 * ## EXAMPLES
 *
 *     wp members-for-kofi member-since set 42 "2024-03-05 18:20:00"
 *     wp members-for-kofi member-since get 42
 *
 * @since 1.4.0
 */
class MemberSinceCommand {

	/**
	 * Sets a member's member-since date.
	 *
	 * ## OPTIONS
	 *
	 * <user-id>
	 * : ID of a user with a Ko-fi membership recorded by this plugin.
	 *
	 * <date>
	 * : When they became a member, read as UTC, e.g. "2024-03-05 18:20:00" or
	 * "2024-03-05". Must be in the past.
	 *
	 * [--force]
	 * : Replace a date that is already recorded.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function set( $args, $assoc_args ) {
		list( $user_id, $date ) = $args;

		$timestamp = self::parse_utc( (string) $date );
		if ( null === $timestamp ) {
			\WP_CLI::error( sprintf( 'Could not read "%s" as a date.', $date ) );
		}

		$result = Membership::set_member_since( (int) $user_id, $timestamp, ! empty( $assoc_args['force'] ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'User %d: member since %s UTC.', (int) $user_id, gmdate( 'Y-m-d H:i:s', $timestamp ) ) );
	}

	/**
	 * Shows a member's member-since date.
	 *
	 * ## OPTIONS
	 *
	 * <user-id>
	 * : User ID.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function get( $args ) {
		$membership = Membership::get( (int) $args[0] );

		if ( null === $membership ) {
			\WP_CLI::error( 'This user has no Ko-fi membership recorded by this plugin.' );
		}

		\WP_CLI::line( null === $membership['member_since'] ? 'unknown' : gmdate( 'Y-m-d H:i:s', $membership['member_since'] ) . ' UTC' );
	}

	/**
	 * Reads a date as UTC.
	 *
	 * @param string $date Date text.
	 * @return int|null Unix time, or null if it is not a date.
	 */
	public static function parse_utc( string $date ): ?int {
		if ( '' === trim( $date ) ) {
			return null;
		}

		try {
			$parsed = new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		return $parsed->getTimestamp();
	}
}
