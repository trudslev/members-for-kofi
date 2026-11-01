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

use MembersForKofi\EmailAliases;
use MembersForKofi\Plugin;
use MembersForKofi\Privacy\PersonalData;
use MembersForKofi\Webhook\Webhook;

/**
 * A member who changes their email keeps their membership.
 *
 * Ko-fi keeps sending the address the supporter gave it. Before 1.5.0 the
 * next payment after an email change created a second account and the real
 * one's role expired.
 */
class EmailAliasesTest extends TestCase {

	/**
	 * Configures the plugin.
	 *
	 * @return void
	 */
	private function configure(): void {
		$this->write_options_raw(
			array(
				'verification_token_sha256' => hash( 'sha256', 'tok' ),
				'only_subscriptions'        => false,
				'tier_role_map'             => array( 'Gold' => 'author' ),
				'default_role'              => 'subscriber',
				'enable_expiry'             => true,
				'role_expiry_days'          => 30,
			)
		);
	}

	/**
	 * Sends a payment through the real webhook.
	 *
	 * @param string $email Donor email.
	 * @return void
	 */
	private function pay( string $email ): void {
		$response = ( new Webhook() )->handle(
			null,
			array(
				'verification_token'      => 'tok',
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'type'                    => 'Subscription',
				'is_subscription_payment' => true,
				'kofi_transaction_id'     => wp_generate_uuid4(),
			)
		);
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Changes a user's email the way WordPress does.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   New address.
	 * @return void
	 */
	private function change_email( int $user_id, string $email ): void {
		$this->assertNotWPError(
			wp_update_user(
				array(
					'ID'         => $user_id,
					'user_email' => $email,
				)
			)
		);
	}

	/**
	 * Number of accounts with an address, current or not.
	 *
	 * @return int
	 */
	private function user_count(): int {
		return (int) count_users()['total_users'];
	}

	/**
	 * The bug: after an email change, the next payment reaches the same
	 * account instead of creating a new one, and the membership stays live.
	 */
	public function test_a_payment_to_the_old_address_reaches_the_same_account(): void {
		$this->configure();
		$this->pay( 'old@example.com' );
		$member = get_user_by( 'email', 'old@example.com' );

		$this->change_email( $member->ID, 'new@example.com' );
		$before = $this->user_count();
		$this->pay( 'old@example.com' );

		$this->assertSame( $before, $this->user_count(), 'No second account.' );
		$this->assertFalse( get_user_by( 'email', 'old@example.com' ) );
		$this->assertSame( 'active', members_for_kofi_get_membership( $member->ID )['status'] );
		$this->assertGreaterThan( time() - 60, (int) get_user_meta( $member->ID, 'kofi_role_assigned_at', true ), 'The renewal counted.' );
	}

	/**
	 * Old addresses are compared without regard to case, as MySQL and Ko-fi do.
	 */
	public function test_the_old_address_matches_in_any_case(): void {
		$this->configure();
		$this->pay( 'Mixed.Case@example.com' );
		$member = get_user_by( 'email', 'mixed.case@example.com' );
		$this->change_email( $member->ID, 'other@example.com' );

		$this->assertSame( $member->ID, EmailAliases::find_user( 'MIXED.case@EXAMPLE.com' )->ID );
	}

	/**
	 * Several changes are all remembered, and changing back makes an address
	 * current again rather than both current and previous.
	 */
	public function test_every_previous_address_is_kept_and_changing_back_drops_it(): void {
		$this->configure();
		$this->pay( 'first@example.com' );
		$member = get_user_by( 'email', 'first@example.com' );

		$this->change_email( $member->ID, 'second@example.com' );
		$this->change_email( $member->ID, 'third@example.com' );
		$this->assertEqualsCanonicalizing( array( 'first@example.com', 'second@example.com' ), EmailAliases::of( $member->ID ) );

		$this->change_email( $member->ID, 'first@example.com' );
		$this->assertEqualsCanonicalizing( array( 'second@example.com', 'third@example.com' ), EmailAliases::of( $member->ID ) );
	}

	/**
	 * Users the plugin has nothing to do with: their old addresses are not
	 * recorded.
	 */
	public function test_other_users_old_addresses_are_not_recorded(): void {
		$user_id = $this->create_user( array( 'user_email' => 'stranger@example.com' ) );
		$this->change_email( $user_id, 'stranger-new@example.com' );

		$this->assertSame( array(), EmailAliases::of( $user_id ) );
	}

	/**
	 * If an old address has since become another user's address, the
	 * payment goes to the user who has it now, and the overlap is logged.
	 */
	public function test_a_current_address_beats_a_previous_one(): void {
		global $wpdb;

		$this->configure();
		$this->pay( 'shared@example.com' );
		$first = get_user_by( 'email', 'shared@example.com' );
		$this->change_email( $first->ID, 'first-new@example.com' );

		$second = $this->create_user( array( 'user_email' => 'shared@example.com' ) );
		$this->pay( 'shared@example.com' );

		$this->assertSame( 'active', members_for_kofi_get_membership( $second )['status'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, test-only read.
		$actions = $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$wpdb->prefix}members_for_kofi_user_logs WHERE user_id = %d", $second ) );
		$this->assertContains( 'Email is also a previous address of another member', $actions );
	}

	/**
	 * A payment found through an old address says so in the user log.
	 */
	public function test_a_match_by_previous_address_is_logged(): void {
		global $wpdb;

		$this->configure();
		$this->pay( 'logged@example.com' );
		$member = get_user_by( 'email', 'logged@example.com' );
		$this->change_email( $member->ID, 'logged-new@example.com' );
		$this->pay( 'logged@example.com' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, test-only read.
		$actions = $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$wpdb->prefix}members_for_kofi_user_logs WHERE user_id = %d", $member->ID ) );
		$this->assertContains( 'Matched by previous email', $actions );
	}

	/**
	 * The export by current address includes the old addresses and the log
	 * rows recorded under them.
	 */
	public function test_the_export_covers_previous_addresses(): void {
		$this->configure();
		$this->pay( 'export-old@example.com' );
		$member = get_user_by( 'email', 'export-old@example.com' );
		$this->change_email( $member->ID, 'export-new@example.com' );

		$data       = PersonalData::export( 'export-new@example.com', 1 )['data'];
		$membership = array_values( array_filter( $data, static fn( $item ) => 'members-for-kofi-membership' === $item['group_id'] ) )[0];
		$fields     = array_column( $membership['data'], 'value', 'name' );

		$this->assertSame( 'export-old@example.com', $fields['previous_emails'] );
		$this->assertContains( 'members-for-kofi-user-log', array_column( $data, 'group_id' ), 'Log rows under the old address are exported.' );
	}

	/**
	 * Erasure keeps the old addresses of a live membership -- they link its
	 * payments -- and removes them once it has ended.
	 */
	public function test_erasure_keeps_old_addresses_only_while_the_membership_is_live(): void {
		$this->configure();
		$this->pay( 'erase-old@example.com' );
		$member = get_user_by( 'email', 'erase-old@example.com' );
		$this->change_email( $member->ID, 'erase-new@example.com' );

		PersonalData::erase( 'erase-new@example.com' );
		$this->assertSame( array( 'erase-old@example.com' ), EmailAliases::of( $member->ID ) );

		delete_user_meta( $member->ID, 'kofi_donation_assigned_role' );
		PersonalData::erase( 'erase-new@example.com' );
		$this->assertSame( array(), EmailAliases::of( $member->ID ) );
	}

	/**
	 * Uninstall removes the old addresses.
	 */
	public function test_uninstall_removes_previous_addresses(): void {
		$this->configure();
		$this->pay( 'uninstall-old@example.com' );
		$member = get_user_by( 'email', 'uninstall-old@example.com' );
		$this->change_email( $member->ID, 'uninstall-new@example.com' );

		Plugin::uninstall();

		$this->assertSame( array(), EmailAliases::of( $member->ID ) );
	}

	/**
	 * The account page can ask whether an email change is safe.
	 */
	public function test_email_changes_are_reported_as_safe(): void {
		$this->assertTrue( function_exists( 'members_for_kofi_supports_email_change' ) );
		$this->assertTrue( members_for_kofi_supports_email_change() );
	}
}
