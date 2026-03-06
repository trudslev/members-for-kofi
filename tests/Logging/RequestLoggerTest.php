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

use MembersForKofi\Logging\RequestLogger;
use WP_UnitTestCase;

/**
 * Class RequestLoggerTest
 *
 * Unit tests for the RequestLogger class in the Members for Ko-fi plugin.
 */
class RequestLoggerTest extends WP_UnitTestCase {

	/**
	 * Sets up the test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Ensure the table is created before each test.
		RequestLogger::create_table();
	}

	/**
	 * Tears down the test environment after each test.
	 */
	protected function tearDown(): void {
		RequestLogger::drop_table();

		parent::tearDown();
	}

	/**
	 * Test that a successful request is logged correctly.
	 */
	public function test_log_successful_request(): void {
		$logger = new RequestLogger();

		$payload = array(
			'email'                  => 'test@example.com',
			'tier_name'              => 'Gold',
			'amount'                 => 10.00,
			'currency'               => 'USD',
			'is_subscription_payment' => true,
			'verification_token'     => 'test-token-12345',
		);

		$logger->log_request( $payload, 200, true, '' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';
		$log        = $wpdb->get_row( "SELECT * FROM `$table_name` ORDER BY id DESC LIMIT 1" );

		$this->assertNotNull( $log );
		$this->assertEquals( 'test@example.com', $log->email );
		$this->assertEquals( 'Gold', $log->tier_name );
		$this->assertEquals( 10.00, $log->amount );
		$this->assertEquals( 'USD', $log->currency );
		$this->assertEquals( 1, $log->is_subscription );
		$this->assertEquals( 200, $log->status_code );
		$this->assertEquals( 1, $log->success );
		$this->assertEquals( '', $log->error );
	}

	/**
	 * Test that a failed request is logged correctly.
	 */
	public function test_log_failed_request(): void {
		$logger = new RequestLogger();

		$payload = array(
			'email'              => 'fail@example.com',
			'verification_token' => 'wrong-token',
		);

		$logger->log_request( $payload, 401, false, 'Unauthorized' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';
		$log        = $wpdb->get_row( "SELECT * FROM `$table_name` ORDER BY id DESC LIMIT 1" );

		$this->assertNotNull( $log );
		$this->assertEquals( 'fail@example.com', $log->email );
		$this->assertEquals( 401, $log->status_code );
		$this->assertEquals( 0, $log->success );
		$this->assertEquals( 'Unauthorized', $log->error );
	}

	/**
	 * Test that table creation works by inserting and retrieving data.
	 */
	public function test_table_creation(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';

		// Try to insert a test record - this will fail if table doesn't exist.
		$logger = new RequestLogger();
		$logger->log_request(
			array(
				'email'              => 'tabletest@example.com',
				'tier_name'          => 'Test',
				'amount'             => 5.00,
				'currency'           => 'USD',
				'is_subscription'    => false,
				'verification_token' => 'test-token',
			),
			200,
			true
		);

		// Retrieve the record to verify table exists and works.
		$log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table_name` WHERE email = %s", 'tabletest@example.com' ) );

		$this->assertNotNull( $log, "Table {$table_name} does not exist or insert failed" );
		$this->assertEquals( 'tabletest@example.com', $log->email );
		$this->assertEquals( 200, $log->status_code );
		$this->assertEquals( 1, $log->success );
	}
}
