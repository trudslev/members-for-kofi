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

use PHPUnit\Framework\TestCase;
use MembersForKofi\Webhook\Webhook;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Logging\RequestLogger;
use Dotenv\Dotenv;

/**
 * Integration tests for the Webhook endpoint with various input scenarios.
 *
 * This test class validates webhook behavior with both expected and unexpected inputs,
 * including edge cases, malformed data, security scenarios, and request logging.
 */
class WebhookIntegrationTest extends TestCase {

	/**
	 * The verification token used for testing.
	 *
	 * @var string
	 */
	private string $valid_token;

	/**
	 * Sets up the test environment before each test.
	 */
	protected function setUp(): void {
		// Load environment variables.
		if ( file_exists( __DIR__ . '/../../.env' ) ) {
			$dotenv = Dotenv::createImmutable( __DIR__ . '/../../' );
			$dotenv->load();
		}

		$this->valid_token = sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ?? 'test-token-12345' );

		// Set up default plugin options.
		update_option(
			'members_for_kofi_options',
			array(
				'verification_token' => $this->valid_token,
				'tier_role_map'      => array(
					'tier' => array( 'Gold', 'Silver', 'Bronze' ),
					'role' => array( 'editor', 'author', 'contributor' ),
				),
				'default_role'       => 'subscriber',
				'only_subscriptions' => false,
				'enable_expiry'      => true,
				'role_expiry_days'   => 35,
			)
		);

		// Create database tables.
		global $wpdb;
		$wpdb->query( UserLogger::get_create_table_sql() );
		$wpdb->query( RequestLogger::get_create_table_sql() );
	}

	/**
	 * Cleans up the test environment after each test.
	 */
	protected function tearDown(): void {
		delete_option( 'members_for_kofi_options' );

		// Drop tables.
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}members_for_kofi_user_logs" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}members_for_kofi_request_logs" );

		// Clean up test users.
		$test_emails = array(
			'valid@example.com',
			'injection@example.com',
			'malformed@example.com',
			'missing@example.com',
			'empty@example.com',
			'special+chars@example.com',
			'unicode@example.com',
			'subscription@example.com',
			'onetime@example.com',
			'tier-gold@example.com',
			'tier-unmapped@example.com',
		);

		foreach ( $test_emails as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				wp_delete_user( $user->ID );
			}
		}
	}

	// ==================== VALID INPUT TESTS ====================

	/**
	 * Test webhook with complete valid subscription payload.
	 */
	public function test_valid_complete_subscription_payload(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token'      => $this->valid_token,
			'email'                   => 'valid@example.com',
			'tier_name'               => 'Gold',
			'amount'                  => 10.00,
			'currency'                => 'USD',
			'is_subscription_payment' => true,
			'message'                 => 'Thanks for the support!',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		// Verify user was created.
		$user = get_user_by( 'email', 'valid@example.com' );
		$this->assertNotFalse( $user );
		$this->assertContains( 'editor', $user->roles );

		// Verify metadata was set.
		$this->assertEquals( 'editor', get_user_meta( $user->ID, 'kofi_donation_assigned_role', true ) );
		$assigned_at = get_user_meta( $user->ID, 'kofi_role_assigned_at', true );
		$this->assertNotEmpty( $assigned_at );

		// Verify request was logged.
		$this->assert_request_logged( 'valid@example.com', 200, true );
	}

	/**
	 * Test webhook with valid one-time payment.
	 */
	public function test_valid_onetime_payment(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token'      => $this->valid_token,
			'email'                   => 'onetime@example.com',
			'tier_name'               => 'Silver',
			'amount'                  => 5.00,
			'currency'                => 'USD',
			'is_subscription_payment' => false,
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );

		// User should be created (only_subscriptions is false).
		$user = get_user_by( 'email', 'onetime@example.com' );
		$this->assertNotFalse( $user );
		$this->assertContains( 'author', $user->roles );
	}

	/**
	 * Test webhook with minimal valid payload (only required fields).
	 */
	public function test_minimal_valid_payload(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'minimal@example.com',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );

		$user = get_user_by( 'email', 'minimal@example.com' );
		$this->assertNotFalse( $user );
	}

	// ==================== AUTHENTICATION TESTS ====================

	/**
	 * Test webhook rejects missing verification token.
	 */
	public function test_reject_missing_verification_token(): void {
		$webhook = new Webhook();

		$payload = array(
			'email'     => 'missing@example.com',
			'tier_name' => 'Gold',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'Missing verification token', $response->get_data()['error'] );

		// Verify request was logged as failure.
		$this->assert_request_logged( 'missing@example.com', 400, false );
	}

	/**
	 * Test webhook rejects invalid verification token.
	 */
	public function test_reject_invalid_verification_token(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => 'wrong-token-123',
			'email'              => 'invalid-token@example.com',
			'tier_name'          => 'Gold',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringContainsString( 'Unauthorized', $response->get_data()['error'] );

		// Verify request was logged.
		$this->assert_request_logged( 'invalid-token@example.com', 401, false );
	}

	/**
	 * Test webhook rejects empty verification token.
	 */
	public function test_reject_empty_verification_token(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => '',
			'email'              => 'empty-token@example.com',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 400, $response->get_status() );
	}

	// ==================== EMAIL VALIDATION TESTS ====================

	/**
	 * Test webhook rejects missing email.
	 */
	public function test_reject_missing_email(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'tier_name'          => 'Gold',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'Invalid email', $response->get_data()['error'] );
	}

	/**
	 * Test webhook rejects invalid email format.
	 */
	public function test_reject_invalid_email_format(): void {
		$webhook = new Webhook();

		$invalid_emails = array(
			'not-an-email',
			'missing-at-sign.com',
			'@no-local-part.com',
			'no-domain@',
			'spaces in@email.com',
		);

		foreach ( $invalid_emails as $invalid_email ) {
			$payload = array(
				'verification_token' => $this->valid_token,
				'email'              => $invalid_email,
			);

			$response = $webhook->handle( null, $payload );
			$this->assertSame( 400, $response->get_status(), "Failed to reject: {$invalid_email}" );
		}
	}

	/**
	 * Test webhook handles special characters in email correctly.
	 */
	public function test_handle_special_chars_in_email(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'special+chars@example.com',
			'tier_name'          => 'Gold',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );

		$user = get_user_by( 'email', 'special+chars@example.com' );
		$this->assertNotFalse( $user );
	}

	// ==================== SUBSCRIPTION MODE TESTS ====================

	/**
	 * Test webhook ignores one-time payments when only_subscriptions is enabled.
	 */
	public function test_ignore_onetime_payment_when_only_subscriptions_enabled(): void {
		update_option(
			'members_for_kofi_options',
			array(
				'verification_token' => $this->valid_token,
				'tier_role_map'      => array(),
				'only_subscriptions' => true,
			)
		);

		$webhook = new Webhook();

		$payload = array(
			'verification_token'      => $this->valid_token,
			'email'                   => 'onetime-ignored@example.com',
			'tier_name'               => 'Gold',
			'is_subscription_payment' => false,
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );

		// User should NOT be created.
		$user = get_user_by( 'email', 'onetime-ignored@example.com' );
		$this->assertFalse( $user );
	}

	/**
	 * Test webhook processes subscriptions when only_subscriptions is enabled.
	 */
	public function test_process_subscription_when_only_subscriptions_enabled(): void {
		update_option(
			'members_for_kofi_options',
			array(
				'verification_token' => $this->valid_token,
				'tier_role_map'      => array(),
				'only_subscriptions' => true,
			)
		);

		$webhook = new Webhook();

		$payload = array(
			'verification_token'      => $this->valid_token,
			'email'                   => 'subscription@example.com',
			'tier_name'               => 'Gold',
			'is_subscription_payment' => true,
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );

		// User should be created.
		$user = get_user_by( 'email', 'subscription@example.com' );
		$this->assertNotFalse( $user );
	}

	// ==================== TIER MAPPING TESTS ====================

	/**
	 * Test role assignment based on tier mapping.
	 */
	public function test_role_assignment_from_tier_map(): void {
		$webhook = new Webhook();

		$test_cases = array(
			array( 'tier' => 'Gold', 'expected_role' => 'editor' ),
			array( 'tier' => 'Silver', 'expected_role' => 'author' ),
			array( 'tier' => 'Bronze', 'expected_role' => 'contributor' ),
		);

		foreach ( $test_cases as $index => $test_case ) {
			$email = "tier-test-{$index}@example.com";

			$payload = array(
				'verification_token' => $this->valid_token,
				'email'              => $email,
				'tier_name'          => $test_case['tier'],
			);

			$response = $webhook->handle( null, $payload );

			$this->assertSame( 200, $response->get_status() );

			$user = get_user_by( 'email', $email );
			$this->assertContains( $test_case['expected_role'], $user->roles, "Tier: {$test_case['tier']}" );
		}
	}

	/**
	 * Test default role assignment for unmapped tier.
	 */
	public function test_default_role_for_unmapped_tier(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'tier-unmapped@example.com',
			'tier_name'          => 'Platinum',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );

		$user = get_user_by( 'email', 'tier-unmapped@example.com' );
		$this->assertContains( 'subscriber', $user->roles );
	}

	/**
	 * Test case-insensitive tier matching.
	 */
	public function test_case_insensitive_tier_matching(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'tier-case@example.com',
			'tier_name'          => 'gold', // Lowercase.
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );

		$user = get_user_by( 'email', 'tier-case@example.com' );
		$this->assertContains( 'editor', $user->roles );
	}

	// ==================== MALFORMED INPUT TESTS ====================

	/**
	 * Test webhook handles null payload gracefully.
	 */
	public function test_reject_null_payload(): void {
		$webhook  = new Webhook();
		$response = $webhook->handle( null, null );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test webhook handles empty payload.
	 */
	public function test_reject_empty_payload(): void {
		$webhook  = new Webhook();
		$response = $webhook->handle( null, array() );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test webhook sanitizes potentially dangerous input.
	 */
	public function test_sanitize_dangerous_input(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'safe@example.com',
			'tier_name'          => '<script>alert("XSS")</script>',
			'message'            => '<img src=x onerror=alert(1)>',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );

		// Verify data was sanitized in the request log fields.
		global $wpdb;
		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';
		$log        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` WHERE email = %s ORDER BY id DESC LIMIT 1",
				'safe@example.com'
			)
		);

		$this->assertNotNull( $log );
		// The tier_name column should be sanitized (tags removed).
		$this->assertStringNotContainsString( '<script>', $log->tier_name );
		$this->assertStringNotContainsString( '<img', $log->tier_name );
	}

	/**
	 * Test webhook handles very long strings.
	 */
	public function test_handle_long_strings(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'long@example.com',
			'tier_name'          => str_repeat( 'A', 1000 ),
			'message'            => str_repeat( 'B', 5000 ),
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test webhook handles numeric values as strings.
	 */
	public function test_handle_numeric_strings(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'numeric@example.com',
			'amount'             => '15.50', // String instead of float.
			'tier_name'          => 'Gold',
		);

		$response = $webhook->handle( null, $payload );

		$this->assertSame( 200, $response->get_status() );
	}

	// ==================== REQUEST LOGGING TESTS ====================

	/**
	 * Test that all requests are logged in the database.
	 */
	public function test_all_requests_are_logged(): void {
		$webhook = new Webhook();

		// Test successful request.
		$webhook->handle(
			null,
			array(
				'verification_token' => $this->valid_token,
				'email'              => 'logged-success@example.com',
			)
		);

		// Test failed request.
		$webhook->handle(
			null,
			array(
				'verification_token' => 'wrong-token',
				'email'              => 'logged-failure@example.com',
			)
		);

		global $wpdb;
		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';
		$count      = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" );

		$this->assertGreaterThanOrEqual( 2, $count );
	}

	/**
	 * Test request log contains full payload for debugging.
	 */
	public function test_request_log_contains_payload(): void {
		$webhook = new Webhook();

		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'payload-test@example.com',
			'tier_name'          => 'Gold',
			'amount'             => 25.00,
		);

		$webhook->handle( null, $payload );

		global $wpdb;
		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';
		$log        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` WHERE email = %s ORDER BY id DESC LIMIT 1",
				'payload-test@example.com'
			)
		);

		$this->assertNotNull( $log );
		$this->assertNotEmpty( $log->payload );

		$stored_payload = json_decode( $log->payload, true );
		$this->assertEquals( 'payload-test@example.com', $stored_payload['email'] );
		$this->assertEquals( 'Gold', $stored_payload['tier_name'] );
	}

	// ==================== HELPER METHODS ====================

	/**
	 * Assert that a request was logged in the database.
	 *
	 * @param string $email       Email to check for.
	 * @param int    $status_code Expected status code.
	 * @param bool   $success     Expected success flag.
	 */
	private function assert_request_logged( string $email, int $status_code, bool $success ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'members_for_kofi_request_logs';

		$log = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` WHERE email = %s ORDER BY id DESC LIMIT 1",
				$email
			)
		);

		$this->assertNotNull( $log, "Request log not found for: {$email}" );
		$this->assertEquals( $status_code, $log->status_code, "Status code mismatch for: {$email}" );
		$this->assertEquals( $success ? 1 : 0, $log->success, "Success flag mismatch for: {$email}" );
	}
}
