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

namespace MembersForKofi\Tests;

use MembersForKofi\Cron\RoleExpiryChecker;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Membership;
use MembersForKofi\Plugin;
use MembersForKofi\Privacy\PersonalData;
use MembersForKofi\Webhook\Webhook;

/**
 * Covers members_for_kofi_get_membership(), the public membership API.
 *
 * Memberships are created and ended through the real webhook and the real
 * expiry job, so the API is tested against what the plugin actually writes.
 */
class MembershipTest extends TestCase {

	/**
	 * Configures the plugin.
	 *
	 * @param array $extra Option overrides.
	 * @return void
	 */
	private function configure( array $extra = array() ): void {
		$this->write_options_raw(
			array_merge(
				array(
					'verification_token_sha256' => hash( 'sha256', 'tok' ),
					'only_subscriptions'        => false,
					'tier_role_map'             => array( 'Gold' => 'author' ),
					'default_role'              => 'subscriber',
					'enable_expiry'             => true,
					'role_expiry_days'          => 30,
				),
				$extra
			)
		);
	}

	/**
	 * Sends a payment through the real webhook.
	 *
	 * @param string $email        Donor email.
	 * @param bool   $subscription Subscription payment.
	 * @param string $tier         Tier name.
	 * @return \WP_User
	 */
	private function pay( string $email, bool $subscription = true, string $tier = 'Gold' ): \WP_User {
		$response = ( new Webhook() )->handle(
			null,
			array(
				'verification_token'      => 'tok',
				'email'                   => $email,
				'tier_name'               => $tier,
				'type'                    => $subscription ? 'Subscription' : 'Donation',
				'is_subscription_payment' => $subscription,
			)
		);
		$this->assertSame( 200, $response->get_status() );

		return get_user_by( 'email', $email );
	}

	/**
	 * Moves a user's last payment into the past.
	 *
	 * @param int $user_id User ID.
	 * @param int $days    Days ago.
	 * @return int The new payment time.
	 */
	private function paid_days_ago( int $user_id, int $days ): int {
		$when = time() - $days * DAY_IN_SECONDS;
		update_user_meta( $user_id, 'kofi_role_assigned_at', $when );

		return $when;
	}

	/**
	 * The function is public, global and has the documented signature.
	 */
	public function test_the_function_exists_with_its_documented_signature(): void {
		$this->assertTrue( function_exists( 'members_for_kofi_get_membership' ) );

		$function = new \ReflectionFunction( 'members_for_kofi_get_membership' );
		$this->assertSame( 'int', (string) $function->getParameters()[0]->getType() );
		$this->assertSame( '?array', (string) $function->getReturnType() );
	}

	/**
	 * A user the plugin never touched, and a user that does not exist, have
	 * no membership.
	 */
	public function test_no_membership_is_null(): void {
		$this->configure();

		$this->assertNull( members_for_kofi_get_membership( $this->create_user() ) );
		$this->assertNull( members_for_kofi_get_membership( 0 ) );
		$this->assertNull( members_for_kofi_get_membership( 999999999 ) );
	}

	/**
	 * A payment makes an active membership with every documented field.
	 */
	public function test_a_payment_makes_an_active_membership(): void {
		$this->configure();
		$user = $this->pay( 'member@example.com' );
		$paid = (int) get_user_meta( $user->ID, 'kofi_role_assigned_at', true );

		$membership = members_for_kofi_get_membership( $user->ID );

		$this->assertSame(
			array( 'status', 'role', 'managed', 'subscription', 'last_payment_at', 'expires_at', 'manage_url', 'cancel_url', 'cancel_hint' ),
			array_keys( $membership )
		);
		$this->assertSame( 'active', $membership['status'] );
		$this->assertSame( 'author', $membership['role'] );
		$this->assertTrue( $membership['managed'] );
		$this->assertTrue( $membership['subscription'] );
		$this->assertSame( $paid, $membership['last_payment_at'] );
		$this->assertSame( (int) strtotime( '+30 days', $paid ), $membership['expires_at'] );
		$this->assertSame( 'https://ko-fi.com/account/', $membership['manage_url'] );
		$this->assertSame( 'https://ko-fi.com/account/', $membership['cancel_url'] );
		$this->assertNotSame( '', $membership['cancel_hint'] );
	}

	/**
	 * A one-off donation is reported as not a subscription; a payment from
	 * before 1.4.0, when this was not recorded, as unknown.
	 */
	public function test_the_subscription_flag_is_true_false_or_unknown(): void {
		$this->configure();

		$this->assertFalse( members_for_kofi_get_membership( $this->pay( 'oneoff@example.com', false )->ID )['subscription'] );

		$legacy = $this->pay( 'legacy@example.com' );
		delete_user_meta( $legacy->ID, Membership::SUBSCRIPTION_META );
		$this->assertNull( members_for_kofi_get_membership( $legacy->ID )['subscription'] );
	}

	/**
	 * With expiry off, a held role stays active and has no end date.
	 */
	public function test_without_expiry_there_is_no_end_date(): void {
		$this->configure( array( 'enable_expiry' => false ) );
		$user = $this->pay( 'forever@example.com' );
		$this->paid_days_ago( $user->ID, 400 );

		$membership = members_for_kofi_get_membership( $user->ID );

		$this->assertSame( 'active', $membership['status'] );
		$this->assertNull( $membership['expires_at'] );
	}

	/**
	 * Past the expiry date the membership is over, even before the daily
	 * expiry job has removed the role.
	 */
	public function test_a_lapsed_membership_is_none_before_the_expiry_job_runs(): void {
		$this->configure();
		$user = $this->pay( 'lapsed@example.com' );
		$paid = $this->paid_days_ago( $user->ID, 31 );

		$membership = members_for_kofi_get_membership( $user->ID );

		$this->assertContains( 'author', get_userdata( $user->ID )->roles, 'Fixture: the job has not run yet.' );
		$this->assertSame( 'none', $membership['status'] );
		$this->assertSame( (int) strtotime( '+30 days', $paid ), $membership['expires_at'] );
	}

	/**
	 * A role an administrator took away ends the membership.
	 */
	public function test_a_removed_role_is_none(): void {
		$this->configure();
		$user = $this->pay( 'removed@example.com' );
		$user->remove_role( 'author' );

		$this->assertSame( 'none', members_for_kofi_get_membership( $user->ID )['status'] );
	}

	/**
	 * After the expiry job has ended the membership, it is still reported
	 * -- as ended, with when it was paid and when it lapsed -- instead of
	 * looking as if it never existed.
	 */
	public function test_an_ended_membership_is_still_reported_after_the_expiry_job(): void {
		$this->configure();
		$user = $this->pay( 'ended@example.com' );
		$paid = $this->paid_days_ago( $user->ID, 31 );

		( new RoleExpiryChecker( new UserLogger() ) )->check_and_remove_expired_roles();

		$this->assertSame( '', get_user_meta( $user->ID, 'kofi_role_assigned_at', true ), 'Fixture: the job removed the tracking meta.' );
		$membership = members_for_kofi_get_membership( $user->ID );
		$this->assertSame( 'none', $membership['status'] );
		$this->assertSame( 'author', $membership['role'] );
		$this->assertSame( $paid, $membership['last_payment_at'] );
		$this->assertSame( (int) strtotime( '+30 days', $paid ), $membership['expires_at'] );
		$this->assertTrue( $membership['subscription'] );
	}

	/**
	 * Paying again after a membership ended starts a new, active one, and the
	 * record of the old one is dropped.
	 */
	public function test_paying_again_after_it_ended_makes_it_active_again(): void {
		$this->configure();
		$user = $this->pay( 'returning@example.com' );
		$this->paid_days_ago( $user->ID, 31 );
		( new RoleExpiryChecker( new UserLogger() ) )->check_and_remove_expired_roles();

		$this->pay( 'returning@example.com' );

		$this->assertSame( 'active', members_for_kofi_get_membership( $user->ID )['status'] );
		$this->assertSame( '', get_user_meta( $user->ID, Membership::LAST_ROLE_META, true ) );
		$this->assertSame( '', get_user_meta( $user->ID, Membership::ENDED_META, true ) );
	}

	/**
	 * A role the user held before paying for it is reported as not managed
	 * by the plugin; its status still follows the Ko-fi payments.
	 */
	public function test_a_role_granted_by_hand_is_not_managed(): void {
		$this->configure();
		$staff = $this->create_user(
			array(
				'role'       => 'author',
				'user_email' => 'staff@example.com',
			)
		);
		$this->pay( 'staff@example.com' );

		$membership = members_for_kofi_get_membership( $staff );
		$this->assertFalse( $membership['managed'] );
		$this->assertSame( 'active', $membership['status'] );

		$this->paid_days_ago( $staff, 31 );
		$this->assertSame( 'none', members_for_kofi_get_membership( $staff )['status'], 'The Ko-fi membership lapsed, though the role stays.' );
		$this->assertContains( 'author', get_userdata( $staff )->roles );
	}

	/**
	 * Everything passes through the filter of the same name.
	 */
	public function test_the_result_is_filterable(): void {
		$this->configure();
		$user   = $this->pay( 'filtered@example.com' );
		$filter = static function ( $membership, $user_id ) use ( $user ) {
			if ( $user_id === $user->ID ) {
				$membership['cancel_url'] = 'https://example.com/cancel';
			}
			return $membership;
		};
		add_filter( 'members_for_kofi_get_membership', $filter, 10, 2 );

		try {
			$this->assertSame( 'https://example.com/cancel', members_for_kofi_get_membership( $user->ID )['cancel_url'] );
		} finally {
			remove_filter( 'members_for_kofi_get_membership', $filter, 10 );
		}
	}

	/**
	 * Uninstall removes the meta this API adds.
	 */
	public function test_uninstall_removes_the_membership_record(): void {
		$this->configure();
		$user = $this->pay( 'uninstall@example.com' );
		Membership::record_end( $user->ID, 'author', time() - 100, time() - 50 );

		Plugin::uninstall();

		foreach ( Membership::meta_keys() as $key ) {
			$this->assertSame( '', get_user_meta( $user->ID, $key, true ), "{$key} must be removed." );
		}
		UserLogger::create_table();
		\MembersForKofi\Logging\RequestLogger::create_table();
	}

	/**
	 * The personal data export includes the membership.
	 */
	public function test_the_export_includes_the_membership(): void {
		$this->configure();
		$this->pay( 'export@example.com' );

		$groups = array_column( PersonalData::export( 'export@example.com', 1 )['data'], 'group_id' );

		$this->assertContains( 'members-for-kofi-membership', $groups );
	}

	/**
	 * Erasure removes the record of an ended membership, but keeps one that
	 * is still active, and says so.
	 */
	public function test_erasure_removes_history_and_keeps_an_active_membership(): void {
		$this->configure();
		$ended = $this->pay( 'erase-ended@example.com' );
		$this->paid_days_ago( $ended->ID, 31 );
		( new RoleExpiryChecker( new UserLogger() ) )->check_and_remove_expired_roles();

		$result = PersonalData::erase( 'erase-ended@example.com' );
		$this->assertTrue( $result['items_removed'] );
		$this->assertNull( members_for_kofi_get_membership( $ended->ID ) );

		$active = $this->pay( 'erase-active@example.com' );
		$result = PersonalData::erase( 'erase-active@example.com' );
		$this->assertTrue( $result['items_retained'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertSame( 'active', members_for_kofi_get_membership( $active->ID )['status'] );
	}
}
