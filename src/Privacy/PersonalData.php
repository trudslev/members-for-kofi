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

namespace MembersForKofi\Privacy;

defined( 'ABSPATH' ) || exit;

use MembersForKofi\Webhook\Webhook;

/**
 * Connects the plugin's logs to WordPress's personal data tools.
 *
 * Both log tables hold donors' email addresses, and the request log keeps the
 * payload: name, Ko-fi message, tier. Without these hooks, Tools > Export
 * Personal Data and Erase Personal Data could not see any of it, so an access
 * or erasure request was answered incompletely.
 */
class PersonalData {

	/**
	 * Rows handled per exporter/eraser page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Hooks the exporter, eraser and suggested privacy-policy text.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function add_exporter( array $exporters ): array {
		$exporters['members-for-kofi'] = array(
			'exporter_friendly_name' => __( 'Members for Ko-fi logs', 'members-for-kofi' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function add_eraser( array $erasers ): array {
		$erasers['members-for-kofi'] = array(
			'eraser_friendly_name' => __( 'Members for Ko-fi logs', 'members-for-kofi' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the log rows recorded for an email address.
	 *
	 * Pages through the user log first, then the request log.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page.
	 * @return array{data: array, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;

		$user_table    = esc_sql( $wpdb->prefix . 'members_for_kofi_user_logs' );
		$request_table = esc_sql( $wpdb->prefix . 'members_for_kofi_request_logs' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables; names come from $wpdb->prefix, values are placeholders.
		$user_rows  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$user_table}` WHERE email = %s", $email ) );
		$user_pages = (int) ceil( $user_rows / self::PAGE_SIZE );

		$data             = array();
		$in_request_phase = $page > $user_pages;

		if ( ! $in_request_phase ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, action, role, amount, currency, timestamp FROM `{$user_table}` WHERE email = %s ORDER BY id LIMIT %d OFFSET %d",
					$email,
					self::PAGE_SIZE,
					( $page - 1 ) * self::PAGE_SIZE
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$data[] = array(
					'group_id'    => 'members-for-kofi-user-log',
					'group_label' => __( 'Ko-fi membership activity', 'members-for-kofi' ),
					'item_id'     => 'members-for-kofi-user-log-' . $row['id'],
					'data'        => self::pairs( $row ),
				);
			}
		} else {
			$request_page = $page - $user_pages;
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, tier_name, amount, currency, is_subscription, payload, status_code, error, timestamp FROM `{$request_table}` WHERE email = %s ORDER BY id LIMIT %d OFFSET %d",
					$email,
					self::PAGE_SIZE,
					( $request_page - 1 ) * self::PAGE_SIZE
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$data[] = array(
					'group_id'    => 'members-for-kofi-request-log',
					'group_label' => __( 'Ko-fi webhook requests', 'members-for-kofi' ),
					'item_id'     => 'members-for-kofi-request-log-' . $row['id'],
					'data'        => self::pairs( $row ),
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'data' => $data,
			// Only the request log, read last, can finish the export.
			'done' => $in_request_phase && count( (array) $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Deletes the log rows recorded for an email address.
	 *
	 * Deleted outright rather than anonymised: a log row's only purpose is to
	 * say what happened to that donor, so without the address it is noise.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page (unused: everything goes in one pass).
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;

		unset( $page );

		$removed = 0;

		foreach ( array( 'members_for_kofi_user_logs', 'members_for_kofi_request_logs' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from the plugin's own table.
			$removed += (int) $wpdb->delete( $wpdb->prefix . $table, array( 'email' => $email ), array( '%s' ) );
		}

		delete_transient( 'members_for_kofi_total_logs' );
		delete_transient( 'members_for_kofi_total_request_logs' );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Takes donors' email addresses out of accounts created before 1.3.0.
	 *
	 * Those accounts used the email as login, display name and nickname, and
	 * derived the author URL slug from it; themes print the display name on
	 * the author archive, which is reachable by number. The login cannot be
	 * renamed through WordPress and is not shown publicly, and donors sign in
	 * with their email either way, so only the public fields change.
	 *
	 * Touches only accounts this plugin made: the login equals the email (the
	 * old code's signature) and there is evidence of the plugin -- its marker,
	 * tracked role or timestamp, or a "User created" row in the user log -- and
	 * within those, only fields still holding the address. Accounts whose role
	 * expired before this release and whose log rows have been pruned carry no
	 * such evidence and are left alone.
	 *
	 * @return int Number of accounts changed.
	 */
	public static function anonymize_legacy_accounts(): int {
		global $wpdb;

		// One indexed lookup on meta_key. 1.3.0 used get_users() with three
		// OR'd EXISTS clauses, which WordPress turns into three self-joins of
		// usermeta: on foodgeek.io (2,746 rows) it never finished, and as it ran
		// on init, every request started another copy until the site jammed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read once, during the upgrade.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s, %s )",
				'kofi_donation_assigned_role',
				'kofi_role_assigned_at',
				Webhook::CREATED_META
			)
		);

		$log_table = esc_sql( $wpdb->prefix . 'members_for_kofi_user_logs' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, read once during the upgrade.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) ) {
			$ids = array_merge(
				$ids,
				$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM `{$log_table}` WHERE action = %s AND user_id > 0", 'User created' ) )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$changed = 0;

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user || '' === $user->user_email || 0 !== strcasecmp( $user->user_login, $user->user_email ) ) {
				continue;
			}

			$email   = $user->user_email;
			$label   = __( 'Supporter', 'members-for-kofi' );
			$update  = array();
			$derived = sanitize_title( sanitize_user( mb_substr( $user->user_login, 0, 50 ), true ) );

			if ( 0 === strcasecmp( $user->display_name, $email ) ) {
				$update['display_name'] = $label;
			}
			if ( '' !== $derived && 0 === strpos( $user->user_nicename, $derived ) ) {
				$update['user_nicename'] = Webhook::generate_login();
			}
			if ( 0 === strcasecmp( (string) get_user_meta( $user_id, 'nickname', true ), $email ) ) {
				$update['nickname'] = $label;
			}

			if ( array() === $update ) {
				continue;
			}

			$update['ID'] = $user_id;
			if ( ! is_wp_error( wp_update_user( $update ) ) ) {
				update_user_meta( $user_id, Webhook::CREATED_META, 1 );
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Suggests privacy-policy text in Settings > Privacy.
	 *
	 * @return void
	 */
	public static function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Members for Ko-fi', 'members-for-kofi' ),
			wp_kses_post(
				'<p>' . __( 'When you support us on Ko-fi, Ko-fi sends this site your email address, the name and message you entered, the amount, and your membership tier. We use your email address to create or find your account here and give it the access your support pays for.', 'members-for-kofi' ) . '</p>'
				. '<p>' . __( 'A record of each payment and of the access granted or removed is kept for the number of days set by the site owner, then deleted automatically when log cleanup is enabled. You can ask for a copy of this data, or for it to be erased, through the site\'s personal data request process.', 'members-for-kofi' ) . '</p>'
			)
		);
	}

	/**
	 * Turns a row into exporter name/value pairs.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private static function pairs( array $row ): array {
		$pairs = array();

		foreach ( $row as $name => $value ) {
			if ( 'id' === $name || null === $value || '' === $value ) {
				continue;
			}
			$pairs[] = array(
				'name'  => $name,
				'value' => (string) $value,
			);
		}

		return $pairs;
	}
}
