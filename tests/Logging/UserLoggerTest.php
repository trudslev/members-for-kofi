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

namespace MembersForKofi\Tests\Logging;

use MembersForKofi\Logging\UserLogger;

/**
 * Class UserLoggerTest
 *
 * Unit tests for the UserLogger class.
 *
 * @package MembersForKofi\Tests\Logging
 */
class UserLoggerTest extends \WP_UnitTestCase {
	/**
	 * Sets up the environment for each test.
	 *
	 * Ensures the user logs table is created before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Ensure the table is created before each test.
		global $wpdb;
		$wpdb->query( UserLogger::get_create_table_sql() );
	}

	/**
	 * Tears down the environment after each test.
	 *
	 * Drops the user logs table to ensure a clean slate.
	 */
	protected function tearDown(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'kofi_members_user_logs';
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

		parent::tearDown();
	}

	/**
	 * Tests logging an action.
	 *
	 * @covers \MembersForKofi\Logging\UserLogger::log_action
	 */
	public function test_log_action(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'kofi_members_user_logs';

		// Log an action.
		$user_logger = new UserLogger();
		$user_logger->log_action( 1, 'test@example.com', 'Test Action', 'test_role', 10.00, 'USD' );

		// Verify the data was inserted into the table.
		$result = $wpdb->get_row( "SELECT * FROM $table_name WHERE user_id = 1", ARRAY_A );
		$this->assertNotEmpty( $result );
		$this->assertEquals( 'test@example.com', $result['email'] );
		$this->assertEquals( 'Test Action', $result['action'] );
		$this->assertEquals( 'test_role', $result['role'] );
		$this->assertEquals( 10.00, (float) $result['amount'] );
		$this->assertEquals( 'USD', $result['currency'] );
	}

	/**
	 * Tests logging a role assignment.
	 *
	 * @covers \MembersForKofi\Logging\UserLogger::log_role_assignment
	 */
	public function test_log_role_assignment(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'kofi_members_user_logs';

		// Log a role assignment.
		$user_logger = new UserLogger();
		$user_logger->log_role_assignment( 1, 'test@example.com', 'subscriber' );

		// Verify the data was inserted into the table.
		// $result = $wpdb->get_row( "SELECT * FROM $table_name WHERE user_id = 1", ARRAY_A );
		$result = $wpdb->get_row( "SELECT * FROM $table_name", ARRAY_A );
		$this->assertNotEmpty( $result );
		$this->assertEquals( 'subscriber', $result['role'] );
		$this->assertEquals( 'Role assigned', $result['action'] );
	}

	/**
	 * Tests logging a role removal.
	 *
	 * @covers \MembersForKofi\Logging\UserLogger::log_role_removal
	 */
	public function test_log_role_removal(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'kofi_members_user_logs';

		// Log a role removal.
		$user_logger = new UserLogger();
		$user_logger->log_role_removal( 1, 'test@example.com', 'subscriber' );

		// Verify the data was inserted into the table.
		$result = $wpdb->get_row( "SELECT * FROM $table_name WHERE user_id = 1", ARRAY_A );

		$this->assertNotEmpty( $result );
		$this->assertEquals( 'subscriber', $result['role'] );
		$this->assertEquals( 'Role removed', $result['action'] );
	}

	/**
	 * Tests logging a donation.
	 *
	 * @covers \MembersForKofi\Logging\UserLogger::log_donation
	 */
	public function test_log_donation(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'kofi_members_user_logs';

		// Log a donation.
		$user_logger = new UserLogger();
		$user_logger->log_donation( 1, 'test@example.com', 5.00, 'USD' );

		// Verify the data was inserted into the table.
		$result = $wpdb->get_row( "SELECT * FROM $table_name WHERE user_id = 1", ARRAY_A );

		$this->assertNotEmpty( $result );
		$this->assertEquals( 'Donation received', $result['action'] );
		$this->assertEquals( 5.00, (float) $result['amount'] );
		$this->assertEquals( 'USD', $result['currency'] );
	}
}
