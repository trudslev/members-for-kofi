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
use PHPUnit\Framework\TestCase;

/**
 * Class RoleExpiryCheckerTest
 *
 * Unit tests for the RoleExpiryChecker class, which handles the removal of expired roles
 * assigned to users based on the Members for Ko-fi plugin settings.
 */
class RoleExpiryCheckerTest extends \WP_UnitTestCase {

	/**
	 * Sets up the test environment before each test.
	 *
	 * This method initializes the default plugin options for role expiry.
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option(
			'kofi_members_options',
			array(
				'role_expiry_days' => 30,
			)
		);

		// Ensure the table is created before each test.
		global $wpdb;
		$wpdb->query( UserLogger::get_create_table_sql() );
	}

	/**
	 * Cleans up the test environment after each test.
	 *
	 * This method removes the logging table created during the test setup.
	 */
	protected function tearDown(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'members_for_kofi_user_logs';
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

		parent::tearDown();
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
		$user_id = $this->factory->user->create(
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
	 * Tests that the remove_expired_roles method does not remove roles that are still valid.
	 *
	 * This test creates a user with an assigned role and an assigned date within the expiry period.
	 * It then checks that the role is not removed and the user meta remains intact.
	 */
	public function test_does_not_remove_valid_role(): void {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-10 days' ) );

		RoleExpiryChecker::remove_expired_roles();

		$user = get_userdata( $user_id );

		$this->assertContains( 'subscriber', $user->roles );
		$this->assertSame( 'subscriber', get_user_meta( $user_id, 'kofi_donation_assigned_role', true ) );
	}

	/**
	 * Tests that the remove_expired_roles method does nothing if no assigned role meta exists.
	 *
	 * This test creates a user without the 'kofi_donation_assigned_role' meta and ensures
	 * that the user's role remains unchanged.
	 */
	public function test_does_nothing_if_no_assigned_role_meta(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-40 days' ) );

		RoleExpiryChecker::remove_expired_roles();

		$user = get_userdata( $user_id );
		$this->assertContains( 'subscriber', $user->roles );
	}

	/**
	 * Tests that the remove_expired_roles method skips users if the assigned role
	 * does not match any of the user's current roles.
	 */
	public function test_skips_user_if_assigned_role_not_in_roles(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'author' ) );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-40 days' ) );

		RoleExpiryChecker::remove_expired_roles();

		$user = get_userdata( $user_id );
		$this->assertContains( 'author', $user->roles );
		$this->assertSame( 'subscriber', get_user_meta( $user_id, 'kofi_donation_assigned_role', true ) );
	}

	/**
	 * Tests that the remove_expired_roles method removes only the assigned role
	 * when the user has multiple roles.
	 *
	 * This test creates a user with multiple roles, assigns one of them as the
	 * donation role, and ensures that only the assigned role is removed while
	 * the other roles remain intact.
	 */
	public function test_removes_only_assigned_role_when_multiple_roles(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_role( 'editor' );

		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-40 days' ) );

		RoleExpiryChecker::remove_expired_roles();

		$user = get_userdata( $user_id );
		$this->assertNotContains( 'subscriber', $user->roles );
		$this->assertContains( 'editor', $user->roles );
	}

	/**
	 * Tests that the remove_expired_roles method skips users if the 'kofi_role_assigned_at' meta is missing.
	 *
	 * This test creates a user with an assigned role but without the 'kofi_role_assigned_at' meta.
	 * It ensures that the user's role remains unchanged.
	 */
	public function test_skips_user_if_no_assigned_at_meta(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );

		RoleExpiryChecker::remove_expired_roles();

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
			'kofi_members_options',
			array(
				'role_expiry_days' => 0,
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $user_id, 'kofi_role_assigned_at', time() - 5 ); // 5 seconds ago

		RoleExpiryChecker::remove_expired_roles();

		$user = get_userdata( $user_id );
		$this->assertNotContains( 'subscriber', $user->roles );
	}
}
