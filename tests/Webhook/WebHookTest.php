<?php
/**
 * This file is part of the Ko-fi Members plugin.
 *
 * Ko-fi Members is free software: you can redistribute it and/or modify
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
 * @package KoFiMembers
 */

use PHPUnit\Framework\TestCase;
use KofiMembers\Webhook\Webhook;
use KofiMembers\Logging\UserLogger;
use KofiMembers\Logging\LoggerFactory;
use Monolog\Logger;
use Dotenv\Dotenv;

/**
 * Unit tests for the Webhook class in the Ko-fi Members plugin.
 *
 * This class contains test cases to validate the behavior of the Webhook class,
 * including token validation, payload handling, and role assignment.
 */
class WebhookTest extends TestCase {

	/**
	 * Logger instance for logging test-related messages.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Sets up the test environment before each test.
	 *
	 * This method initializes environment variables, plugin options,
	 * and the logger instance required for the tests.
	 */
	protected function setUp(): void {
		// Load environment variables (if needed).
		if ( file_exists( __DIR__ . '/../../.env' ) ) {
			$dotenv = Dotenv::createImmutable( __DIR__ . '/../../' );
			$dotenv->load();
		}

		// Set the expected plugin options so the webhook can validate tokens.
		update_option(
			'kofi_members_options',
			array(
				'verification_token' => isset( $_ENV['KOFI_VERIFICATION_TOKEN'] ) ? sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ) : 'fallback-token',
				'tier_role_map'      => array(),
				'only_subscriptions' => false,
			)
		);

		// You can still use your logger factory.
		$this->logger = LoggerFactory::create_logger(
			array(
				'log_enabled' => false,
			)
		);

		// Ensure the table is created before each test.
		global $wpdb;
		$wpdb->query( UserLogger::get_create_table_sql() );
	}

	/**
	 * Cleans up the test environment after each test.
	 *
	 * This method deletes plugin options, resets the logger, and drops the user logs table
	 * to ensure a clean state for subsequent tests.
	 */
	protected function tearDown(): void {
		// Clean up the options after tests.
		delete_option( 'kofi_members_options' );

		// Reset the logger to avoid side effects in other tests.
		LoggerFactory::reset();

		// Drop the user logs table after tests.
		global $wpdb;
		$table_name = $wpdb->prefix . 'kofi_members_user_logs';
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
	}

	/**
	 * Tests that the webhook rejects requests with an invalid verification token.
	 */
	public function test_rejects_invalid_token(): void {
		$webhook = new Webhook( $this->logger );

		$response = $webhook->handle(
			null,
			array(
				'verification_token' => 'wrong-token',
				'email'              => 'user@example.com',
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Tests that the webhook rejects requests missing the email field.
	 */
	public function test_rejects_missing_email(): void {
		$webhook = new Webhook( $this->logger );

		$response = $webhook->handle(
			null,
			array(
				'verification_token' => isset( $_ENV['KOFI_VERIFICATION_TOKEN'] ) ? sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ) : 'fallback-token',
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Tests that the webhook correctly handles a valid subscription payload.
	 */
	public function test_handles_valid_subscription_payload(): void {
		$webhook = new Webhook( $this->logger );

		$response = $webhook->handle(
			null,
			array(
				'verification_token'      => $_ENV['KOFI_VERIFICATION_TOKEN'] ?? 'fallback-token',
				'email'                   => 'user@example.com',
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Tests that the webhook rejects requests missing the verification token.
	 */
	public function test_rejects_missing_token(): void {
		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle( null, array() );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Tests that the webhook accepts requests with a valid verification token.
	 */
	public function test_accepts_valid_token(): void {
		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle( null, array( 'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ) ) );
		$this->assertNotEquals( 403, $response->get_status() );
	}

	/**
	 * Tests that the webhook rejects requests with a missing payload.
	 */
	public function test_rejects_missing_payload(): void {
		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle( null, array( 'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ) ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Tests that the webhook logs appropriate messages when handling a valid payload.
	 */
	public function test_logs_with_valid_payload(): void {
		$messages    = array();
		$mock_logger = $this->getMockBuilder( Logger::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'info' ) )
			->getMock();

		$mock_logger->method( 'info' )
			->willReturnCallback(
				function ( $message ) use ( &$messages ) {
					$messages[] = $message;
				}
			);

		$request = new \WP_REST_Request( 'POST', '/kofi-members/v1/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			json_encode(
				array(
					'email'                   => 'test@example.com',
					'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
					'tier_name'               => 'Gold',
					'is_subscription_payment' => true,
				)
			)
		);

		$webhook  = new Webhook( $mock_logger );
		$response = $webhook->handle( $request, null );

		// Response should be 200 OK.
		$this->assertSame( 200, $response->get_status() );

		// Assert both log messages were captured.
		$this->assertTrue(
			$this->log_message_found( $messages, 'Webhook received' ),
			'Expected "Webhook received" to be logged'
		);

		$this->assertTrue(
			$this->log_message_found( $messages, 'New user created' ),
			'Expected "New user created" to be logged'
		);
	}

	/**
	 * Tests that the webhook skips user creation for one-time payments
	 * when the "only subscriptions" option is enabled.
	 */
	public function test_skips_user_creation_for_one_time_payment_when_only_subscriptions_is_true(): void {
		update_option(
			'kofi_members_options',
			array(
				'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_role_map'      => array(),
				'only_subscriptions' => true,
			)
		);

		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle(
			null,
			array(
				'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'email'                   => 'user@example.com',
				'tier_name'               => 'Gold',
				'is_subscription_payment' => false,
			)
		);

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Tests that the webhook handles user creation failure gracefully.
	 *
	 * This test simulates a failure during user creation and verifies
	 * that the webhook responds with a 500 status code.
	 */
	public function test_handles_user_creation_failure(): void {
		$mock_webhook = new class($this->logger) extends Webhook {
			/**
			 * Creates a user with the given email address.
			 *
			 * @param string $email The email address of the user to create.
			 * @return mixed|\WP_Error The created user object or a WP_Error on failure.
			 */
			protected function create_user( $email ) {
				return new \WP_Error( 'fail', 'Simulated failure' );
			}
		};

		$response = $mock_webhook->handle(
			null,
			array(
				'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'email'                   => 'fail-user@example.com',
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 500, $response->get_status() );
	}

	/**
	 * Tests that the webhook assigns the correct role based on the tier name.
	 */
	public function test_assigns_role_based_on_tier(): void {
		update_option(
			'kofi_members_options',
			array(
				'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_role_map'      => array( 'Gold' => 'subscriber' ),
				'only_subscriptions' => false,
			)
		);

		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle(
			null,
			array(
				'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'email'                   => 'tier-user@example.com',
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$user = get_user_by( 'email', 'tier-user@example.com' );
		$this->assertContains( 'subscriber', $user->roles );
	}

	/**
	 * Tests that no role is assigned when the tier is unknown.
	 *
	 * This test verifies that users are not assigned any roles if the tier
	 * specified in the payload does not exist in the tier-to-role mapping.
	 */
	public function test_no_role_assigned_when_tier_unknown(): void {
		update_option(
			'kofi_members_options',
			array(
				'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_role_map'      => array(), // No mapping
				'only_subscriptions' => false,
			)
		);

		$email   = 'unknown@example.com';
		$user_id = wp_create_user( $email, wp_generate_password(), $email );

		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle(
			null,
			array(
				'email'                   => $email,
				'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_name'               => 'NoSuchTier',
				'is_subscription_payment' => true,
			)
		);

		$user = get_userdata( $user_id );
		$this->assertEmpty( array_diff( $user->roles, array( 'subscriber' ) ) );
	}

	/**
	 * Tests that the default role is assigned when the tier is unknown.
	 *
	 * This test verifies that if the tier specified in the payload does not exist
	 * in the tier-to-role mapping, the default role is assigned to the user.
	 */
	public function test_assigns_default_role_when_tier_unknown(): void {
		update_option(
			'kofi_members_options',
			array(
				'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_role_map'      => array(), // No matching tier.
				'default_role'       => 'contributor',
				'only_subscriptions' => false,
			)
		);

		$email   = 'defaultrole@example.com';
		$user_id = wp_create_user( $email, wp_generate_password(), $email );

		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle(
			null,
			array(
				'email'                   => $email,
				'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_name'               => 'NonExistentTier',
				'is_subscription_payment' => true,
			)
		);

		$user = get_userdata( $user_id );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'contributor', $user->roles );
	}


	/**
	 * Tests that user meta is updated correctly when a role is assigned.
	 *
	 * This test verifies that the appropriate meta fields are set when a user
	 * is assigned a role based on their tier.
	 */
	public function test_user_meta_updated_on_role_assignment(): void {
		update_option(
			'kofi_members_options',
			array(
				'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_role_map'      => array(
					'tier' => array( 'Gold' ),
					'role' => array( 'editor' ),
				),
				'only_subscriptions' => false,
			)
		);

		$email = 'meta@example.com';

		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle(
			null,
			array(
				'email'                   => $email,
				'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$user = get_user_by( 'email', $email );

		$this->assertSame( 'editor', get_user_meta( $user->ID, 'kofi_donation_assigned_role', true ) );
		$assigned_at = get_user_meta( $user->ID, 'kofi_role_assigned_at', true );
		$this->assertNotEmpty( $assigned_at );
		$this->assertIsNumeric( $assigned_at );
		$this->assertGreaterThan( time() - 60, (int) $assigned_at, 'Timestamp is recent' );
	}

	/**
	 * Tests that the webhook assigns the correct role based on the tier map.
	 *
	 * This test verifies that users are assigned roles according to the tier-to-role mapping
	 * specified in the plugin options.
	 */
	public function test_assigns_role_from_tier_map(): void {
		update_option(
			'kofi_members_options',
			array(
				'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_role_map'      => array(
					'tier' => array( 'Gold', 'Silver' ),
					'role' => array( 'editor', 'author' ),
				),
				'default_role'       => 'subscriber',
				'only_subscriptions' => false,
			)
		);

		$email    = 'gold@example.com';
		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle(
			null,
			array(
				'email'                   => $email,
				'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$user = get_user_by( 'email', $email );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'editor', $user->roles );
	}

	/**
	 * Tests that the default role is assigned when the tier is not mapped.
	 *
	 * This test verifies that if the tier specified in the payload does not exist
	 * in the tier-to-role mapping, the default role is assigned to the user.
	 */
	public function test_assigns_default_role_if_tier_not_mapped(): void {
		update_option(
			'kofi_members_options',
			array(
				'verification_token' => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_role_map'      => array(
					'tier' => array( 'Gold' ),
					'role' => array( 'editor' ),
				),
				'default_role'       => 'subscriber',
				'only_subscriptions' => false,
			)
		);

		$email = 'fallback@example.com';

		$webhook  = new Webhook( $this->logger );
		$response = $webhook->handle(
			null,
			array(
				'email'                   => $email,
				'verification_token'      => sanitize_text_field( $_ENV['KOFI_VERIFICATION_TOKEN'] ),
				'tier_name'               => 'Platinum', // Not mapped
				'is_subscription_payment' => true,
			)
		);

		$user = get_user_by( 'email', $email );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'subscriber', $user->roles );
	}

	/**
	 * Checks if a specific log message exists in the provided messages array.
	 *
	 * @param array  $messages Array of log messages.
	 * @param string $needle   The specific message to search for.
	 * @return bool True if the message is found, false otherwise.
	 */
	private function log_message_found( array $messages, string $needle ): bool {
		foreach ( $messages as $msg ) {
			if ( str_contains( $msg, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
