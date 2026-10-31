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
 * Reports a user's Ko-fi membership, for other plugins and themes.
 *
 * The public entry point is the global function
 * members_for_kofi_get_membership(); this class holds the rules behind it.
 *
 * While a membership is tracked, it is described by the meta the webhook
 * writes on every payment. When it ends, the expiry job removes that meta, so
 * it first writes a short record of the membership that ended: without it an
 * ended membership would look exactly like one that never existed.
 *
 * @since 1.4.0
 */
class Membership {

	/**
	 * Role the plugin last tracked, kept after the membership ended.
	 *
	 * @var string
	 */
	public const LAST_ROLE_META = 'kofi_last_assigned_role';

	/**
	 * Time of the last payment, kept after the membership ended.
	 *
	 * @var string
	 */
	public const LAST_PAYMENT_META = 'kofi_last_payment_at';

	/**
	 * When access lapsed, recorded when the membership ended.
	 *
	 * @var string
	 */
	public const ENDED_META = 'kofi_membership_ended_at';

	/**
	 * Whether the last payment was a subscription payment ('1' or '0').
	 *
	 * Absent for payments made before 1.4.0, which is reported as null:
	 * unknown, not false.
	 *
	 * @var string
	 */
	public const SUBSCRIPTION_META = 'kofi_last_payment_subscription';

	/**
	 * Where a supporter manages their Ko-fi account and subscriptions.
	 *
	 * @var string
	 */
	public const KOFI_ACCOUNT_URL = 'https://ko-fi.com/account/';

	/**
	 * Describes a user's Ko-fi membership.
	 *
	 * @param int $user_id User ID.
	 * @return array|null Null when the plugin never assigned this user a role
	 *                    (or no record of it remains). Otherwise:
	 *                    - status:          'active' while the membership has
	 *                                       not lapsed and the role is held;
	 *                                       'none' after expiry or removal.
	 *                    - role:            Role slug the membership grants.
	 *                    - managed:         False when the user held the role
	 *                                       before paying for it: the plugin
	 *                                       tracks it but never removes it.
	 *                    - subscription:    True for a subscription payment,
	 *                                       false for a one-off donation, null
	 *                                       when unknown (paid before 1.4.0).
	 *                    - last_payment_at: Unix time of the last payment.
	 *                    - expires_at:      Unix time access lapses (or
	 *                                       lapsed) without a new payment;
	 *                                       null when expiry is disabled.
	 *                    - manage_url:      Where the supporter manages Ko-fi.
	 *                    - cancel_url:      Where they cancel a subscription.
	 *                    - cancel_hint:     How to cancel, in words.
	 */
	public static function get( int $user_id ): ?array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user ) {
			return null;
		}

		$tracked = (string) get_user_meta( $user_id, 'kofi_donation_assigned_role', true );

		if ( '' !== $tracked ) {
			$membership = self::tracked( $user, $tracked );
		} else {
			$membership = self::ended( $user );
		}

		if ( null === $membership ) {
			return null;
		}

		/**
		 * Filters a user's Ko-fi membership as reported to other code.
		 *
		 * Use it to point manage_url and cancel_url somewhere else, reword
		 * cancel_hint, or add fields of your own.
		 *
		 * @param array $membership See Membership::get().
		 * @param int   $user_id    User ID.
		 */
		$filtered = apply_filters( 'members_for_kofi_get_membership', $membership, $user_id );

		return is_array( $filtered ) ? $filtered : null;
	}

	/**
	 * Records a membership that is ending, before its tracking meta goes.
	 *
	 * Called by the expiry job.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $role       Role that was tracked.
	 * @param int    $last_paid  Time of the last payment.
	 * @param int    $lapsed_at  When access lapsed.
	 * @return void
	 */
	public static function record_end( int $user_id, string $role, int $last_paid, int $lapsed_at ): void {
		update_user_meta( $user_id, self::LAST_ROLE_META, $role );
		update_user_meta( $user_id, self::LAST_PAYMENT_META, $last_paid );
		update_user_meta( $user_id, self::ENDED_META, $lapsed_at );
	}

	/**
	 * Records a payment. Called by the webhook when it assigns a role.
	 *
	 * A new payment starts a new membership, so the record of an earlier
	 * one that ended is dropped.
	 *
	 * @param int  $user_id      User ID.
	 * @param bool $subscription Whether it was a subscription payment.
	 * @return void
	 */
	public static function record_payment( int $user_id, bool $subscription ): void {
		update_user_meta( $user_id, self::SUBSCRIPTION_META, $subscription ? '1' : '0' );
		self::forget_end( $user_id );
	}

	/**
	 * Deletes the record of a membership that ended.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when there was a record to delete.
	 */
	public static function forget_end( int $user_id ): bool {
		$had = '' !== (string) get_user_meta( $user_id, self::LAST_ROLE_META, true );

		delete_user_meta( $user_id, self::LAST_ROLE_META );
		delete_user_meta( $user_id, self::LAST_PAYMENT_META );
		delete_user_meta( $user_id, self::ENDED_META );

		return $had;
	}

	/**
	 * Every user meta key this class writes, for uninstall and erasure.
	 *
	 * @return array<string>
	 */
	public static function meta_keys(): array {
		return array( self::LAST_ROLE_META, self::LAST_PAYMENT_META, self::ENDED_META, self::SUBSCRIPTION_META );
	}

	/**
	 * A membership the plugin is still tracking.
	 *
	 * @param \WP_User $user User.
	 * @param string   $role Tracked role.
	 * @return array
	 */
	private static function tracked( \WP_User $user, string $role ): array {
		$last_paid = (int) get_user_meta( $user->ID, 'kofi_role_assigned_at', true );
		$expires   = self::expiry_enabled() && $last_paid > 0
			? (int) strtotime( '+' . self::expiry_days() . ' days', $last_paid )
			: null;

		$held   = in_array( $role, (array) $user->roles, true );
		$lapsed = null !== $expires && time() > $expires;

		return self::describe(
			$user,
			array(
				'status'          => ( $held && ! $lapsed ) ? 'active' : 'none',
				'role'            => $role,
				'managed'         => (string) get_user_meta( $user->ID, Webhook::PREEXISTING_ROLE_META, true ) !== $role,
				'last_payment_at' => $last_paid > 0 ? $last_paid : null,
				'expires_at'      => $expires,
			)
		);
	}

	/**
	 * A membership that ended, from the record the expiry job left.
	 *
	 * @param \WP_User $user User.
	 * @return array|null Null when there is no such record.
	 */
	private static function ended( \WP_User $user ): ?array {
		$role = (string) get_user_meta( $user->ID, self::LAST_ROLE_META, true );

		if ( '' === $role ) {
			return null;
		}

		$last_paid = (int) get_user_meta( $user->ID, self::LAST_PAYMENT_META, true );
		$ended_at  = (int) get_user_meta( $user->ID, self::ENDED_META, true );

		return self::describe(
			$user,
			array(
				'status'          => 'none',
				'role'            => $role,
				'managed'         => true,
				'last_payment_at' => $last_paid > 0 ? $last_paid : null,
				'expires_at'      => $ended_at > 0 ? $ended_at : null,
			)
		);
	}

	/**
	 * Adds the fields every membership carries.
	 *
	 * @param \WP_User $user   User.
	 * @param array    $fields Status fields.
	 * @return array
	 */
	private static function describe( \WP_User $user, array $fields ): array {
		$subscription = get_user_meta( $user->ID, self::SUBSCRIPTION_META, true );

		return array(
			'status'          => $fields['status'],
			'role'            => $fields['role'],
			'managed'         => $fields['managed'],
			'subscription'    => '' === $subscription ? null : '1' === (string) $subscription,
			'last_payment_at' => $fields['last_payment_at'],
			'expires_at'      => $fields['expires_at'],
			'manage_url'      => self::KOFI_ACCOUNT_URL,
			'cancel_url'      => self::KOFI_ACCOUNT_URL,
			'cancel_hint'     => __( 'Sign in to Ko-fi, then go to Account settings and Subscriptions. If you pay with PayPal, you can also cancel there.', 'members-for-kofi' ),
		);
	}

	/**
	 * Whether role expiry is enabled. A missing setting means enabled, as in
	 * the expiry job.
	 *
	 * @return bool
	 */
	private static function expiry_enabled(): bool {
		$options = get_option( 'members_for_kofi_options', array() );

		return ! empty( is_array( $options ) && array_key_exists( 'enable_expiry', $options ) ? $options['enable_expiry'] : true );
	}

	/**
	 * Days a payment keeps the role, as the expiry job reads it.
	 *
	 * @return int
	 */
	private static function expiry_days(): int {
		$options = get_option( 'members_for_kofi_options', array() );

		return is_array( $options ) && isset( $options['role_expiry_days'] ) ? absint( $options['role_expiry_days'] ) : 35;
	}
}
