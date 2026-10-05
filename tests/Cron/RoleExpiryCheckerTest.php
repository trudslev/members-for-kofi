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

use MembersForKofi\Cron\RoleExpiryChecker;
use MembersForKofi\Logging\UserLogger;

/**
 * Class RoleExpiryCheckerTest
 *
 * Unit tests for the RoleExpiryChecker class, which handles the removal of expired roles
 * assigned to users based on the Members for Ko-fi plugin settings.
 */
class RoleExpiryCheckerTest extends \MembersForKofi\Tests\TestCase {

	/**
	 * Sets up the test environment before each test.
	 *
	 * This method initializes the default plugin options for role expiry.
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option(
			'members_for_kofi_options',
			array(
				'role_expiry_days' => 30,
			)
		);
	}

	/**
	 * Tests that the remove_expired_roles method removes roles that have expired.
	 *
	 * This test creates a user with an assigned role and an assigned date that is older than the expiry period.
	 * It then checks that the role is removed and the user meta is cleared.
	 */
	public function test_removes_expired_role(): void {
		// Create a mock for the UserLogger class.
		$mock_logger = $this->createMock( UserLogger::class );

		// Expect the log_role_removal method to be called once with specific arguments.
		$mock_logger->expects( $this->once() )
			->method( 'log_role_removal' )
			->with(
				$this->isType( 'int' ), // User ID.
				$this->isType( 'string' ), // Email.
				$this->equalTo( 'subscriber' ) // Role.
			);

		// Pass the mock logger to the RoleExpiryChecker.
		$role_expiry_checker = new RoleExpiryChecker( $mock_logger );

		// Create a test user with an expired role.
		$user_id = $this->create_user(
			array(
				'role' => 'subscriber',
			)
		);

		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-31 days' ) );

		// Call the method to remove expired roles.
		$role_expiry_checker->check_and_remove_expired_roles();

		// Verify the role was removed.
		$user = get_userdata( $user_id );
		$this->assertNotContains( 'subscriber', $user->roles );
		$this->assertEmpty( get_user_meta( $user_id, 'kofi_donation_assigned_role', true ) );
		$this->assertEmpty( get_user_meta( $user_id, 'kofi_role_assigned_at', true ) );
	}

	/**
	 * Tests that the check_and_remove_expired_roles method does not remove roles that are still valid.
	 *
	 * This test creates a user with an assigned role and an assigned date within the expiry period.
	 * It then checks that the role is not removed and the user meta remains intact.
	 */
	public function test_does_not_remove_valid_role(): void {
		$user_id = $this->create_user(
			array(
				'role' => 'subscriber',
			)
		);

		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-10 days' ) );

		$role_expiry_checker = new RoleExpiryChecker( new UserLogger() );
		$role_expiry_checker->check_and_remove_expired_roles();

		$user = get_userdata( $user_id );

		$this->assertContains( 'subscriber', $user->roles );
		$this->assertSame( 'subscriber', get_user_meta( $user_id, 'kofi_donation_assigned_role', true ) );
	}

	/**
	 * Tests that the check_and_remove_expired_roles method does nothing if no assigned role meta exists.
	 *
	 * This test creates a user without the 'kofi_donation_assigned_role' meta and ensures
	 * that the user's role remains unchanged.
	 */
	public function test_does_nothing_if_no_assigned_role_meta(): void {
		$user_id = $this->create_user( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-40 days' ) );

		$role_expiry_checker = new RoleExpiryChecker( new UserLogger() );
		$role_expiry_checker->check_and_remove_expired_roles();

		$user = get_userdata( $user_id );
		$this->assertContains( 'subscriber', $user->roles );
	}

	/**
	 * Tests that the check_and_remove_expired_roles method removes only the assigned role
	 * when the user has multiple roles.
	 *
	 * This test creates a user with multiple roles, assigns one of them as the
	 * donation role, and ensures that only the assigned role is removed while
	 * the other roles remain intact.
	 */
	public function test_removes_only_assigned_role_when_multiple_roles(): void {
		$user_id = $this->create_user( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_role( 'editor' );

		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-40 days' ) );

		$role_expiry_checker = new RoleExpiryChecker( new UserLogger() );
		$role_expiry_checker->check_and_remove_expired_roles();

		$user = get_userdata( $user_id );
		$this->assertNotContains( 'subscriber', $user->roles );
		$this->assertContains( 'editor', $user->roles );
	}

	/**
	 * Tests that the check_and_remove_expired_roles method skips users if the 'kofi_role_assigned_at' meta is missing.
	 *
	 * This test creates a user with an assigned role but without the 'kofi_role_assigned_at' meta.
	 * It ensures that the user's role remains unchanged.
	 */
	public function test_skips_user_if_no_assigned_at_meta(): void {
		$user_id = $this->create_user( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );

		$role_expiry_checker = new RoleExpiryChecker( new UserLogger() );
		$role_expiry_checker->check_and_remove_expired_roles();

		$user = get_userdata( $user_id );
		$this->assertContains( 'subscriber', $user->roles );
	}

	/**
	 * Tests that roles expire immediately when the role expiry days option is set to zero.
	 *
	 * This test creates a user with an assigned role and ensures that the role is removed
	 * immediately when the expiry period is set to zero days.
	 */
	public function test_expires_immediately_when_role_expiry_days_zero(): void {
		update_option(
			'members_for_kofi_options',
			array(
				'role_expiry_days' => 0,
			)
		);

		$user_id = $this->create_user( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', time() - 5 ); // 5 seconds ago

		$role_expiry_checker = new RoleExpiryChecker( new UserLogger() );
		$role_expiry_checker->check_and_remove_expired_roles();

		$user = get_userdata( $user_id );
		$this->assertNotContains( 'subscriber', $user->roles );
	}

	/**
	 * Turning "Enable Expiry" off actually stops roles being removed.
	 *
	 * The setting was rendered, saved and documented, but nothing ever read it:
	 * a site that deliberately disabled expiry still had its supporters' roles
	 * taken away after role_expiry_days.
	 */
	public function test_does_not_remove_role_when_expiry_is_disabled(): void {
		update_option(
			'members_for_kofi_options',
			array(
				'enable_expiry'    => false,
				'role_expiry_days' => 30,
			)
		);

		$user_id = $this->create_user();
		$user    = get_user_by( 'ID', $user_id );
		$user->add_role( 'editor' );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'editor' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-400 days' ) );

		$checker = new RoleExpiryChecker( new UserLogger() );
		$checker->check_and_remove_expired_roles();

		$user = get_user_by( 'ID', $user_id );
		$this->assertContains(
			'editor',
			$user->roles,
			'With expiry disabled the role must survive, however old the assignment is'
		);
		$this->assertNotEmpty(
			get_user_meta( $user_id, 'kofi_role_assigned_at', true ),
			'The tracking meta must be left alone too, or a later re-enable has nothing to work from'
		);
	}

	/**
	 * With the setting explicitly on, expiry behaves as before.
	 */
	public function test_removes_role_when_expiry_is_enabled(): void {
		update_option(
			'members_for_kofi_options',
			array(
				'enable_expiry'    => true,
				'role_expiry_days' => 30,
			)
		);

		$user_id = $this->create_user();
		$user    = get_user_by( 'ID', $user_id );
		$user->add_role( 'editor' );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'editor' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-400 days' ) );

		$checker = new RoleExpiryChecker( new UserLogger() );
		$checker->check_and_remove_expired_roles();

		$user = get_user_by( 'ID', $user_id );
		$this->assertNotContains( 'editor', $user->roles );
	}

	/**
	 * An install predating the toggle keeps expiring, rather than silently
	 * stopping the moment it updates.
	 */
	public function test_expiry_still_runs_when_the_setting_is_absent(): void {
		update_option(
			'members_for_kofi_options',
			array(
				'role_expiry_days' => 30,
			)
		);

		$user_id = $this->create_user();
		$user    = get_user_by( 'ID', $user_id );
		$user->add_role( 'editor' );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'editor' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-400 days' ) );

		$checker = new RoleExpiryChecker( new UserLogger() );
		$checker->check_and_remove_expired_roles();

		$user = get_user_by( 'ID', $user_id );
		$this->assertNotContains( 'editor', $user->roles );
	}

	/**
	 * Sends a payment for an email through the real webhook.
	 *
	 * @param string $email Email.
	 * @param string $tier  Tier name.
	 * @return void
	 */
	private function pay( string $email, string $tier ): void {
		$this->write_options_raw(
			array(
				'verification_token_sha256' => hash( 'sha256', 'tok' ),
				'tier_role_map'             => array(
					'Silver' => 'author',
					'Gold'   => 'editor',
				),
				'role_expiry_days'          => 30,
			)
		);

		( new \MembersForKofi\Webhook\Webhook() )->handle(
			null,
			array(
				'verification_token' => 'tok',
				'email'              => $email,
				'tier_name'          => $tier,
			)
		);
	}

	/**
	 * A role an admin granted by hand survives expiry, even when the donor's
	 * tier maps to the same role.
	 */
	public function test_a_role_granted_by_hand_survives_expiry(): void {
		$user_id = $this->create_user(
			array(
				'role'       => 'author',
				'user_email' => 'staff@example.com',
			)
		);

		$this->pay( 'staff@example.com', 'Silver' );
		$this->assertSame( 'author', get_user_meta( $user_id, \MembersForKofi\Webhook\Webhook::PREEXISTING_ROLE_META, true ) );

		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-31 days' ) );
		( new RoleExpiryChecker( new UserLogger() ) )->check_and_remove_expired_roles();

		$this->assertContains( 'author', get_userdata( $user_id )->roles, 'The plugin did not grant this role, so it must not take it.' );
		$this->assertEmpty( get_user_meta( $user_id, 'kofi_donation_assigned_role', true ), 'Tracking ends.' );
		$this->assertEmpty( get_user_meta( $user_id, \MembersForKofi\Webhook\Webhook::PREEXISTING_ROLE_META, true ) );
	}

	/**
	 * A tier change keeps a hand-granted role too, and still removes one the
	 * plugin granted.
	 */
	public function test_a_tier_change_removes_only_what_the_plugin_granted(): void {
		$staff = $this->create_user(
			array(
				'role'       => 'author',
				'user_email' => 'staff2@example.com',
			)
		);
		$this->pay( 'staff2@example.com', 'Silver' );
		$this->pay( 'staff2@example.com', 'Gold' );
		$this->assertContains( 'author', get_userdata( $staff )->roles );
		$this->assertContains( 'editor', get_userdata( $staff )->roles );

		$this->pay( 'donor@example.com', 'Silver' );
		$this->pay( 'donor@example.com', 'Gold' );
		$donor = get_user_by( 'email', 'donor@example.com' );
		$this->assertNotContains( 'author', $donor->roles );
		$this->assertContains( 'editor', $donor->roles );
	}

	/**
	 * A renewal that lands while expiry runs is not undone.
	 *
	 * The checker reads the old timestamp from its cache; the renewal is
	 * written behind it, as another request would. The timestamp must be read
	 * again before anything is removed.
	 */
	public function test_a_renewal_landing_mid_run_is_kept(): void {
		global $wpdb;

		$user_id = $this->create_user( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-31 days' ) );
		get_user_meta( $user_id ); // Warm the cache with the old timestamp.

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- Simulating another request's write, which bypasses this request's cache.
		$wpdb->update(
			$wpdb->usermeta,
			array( 'meta_value' => (string) time() ),
			array(
				'user_id'  => $user_id,
				'meta_key' => 'kofi_role_assigned_at',
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery

		( new RoleExpiryChecker( new UserLogger() ) )->check_and_remove_expired_roles();

		$this->assertContains( 'subscriber', get_userdata( $user_id )->roles );
	}
}
