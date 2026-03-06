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
use Dotenv\Dotenv;

/**
 * HTTP Integration tests for the Webhook endpoint.
 *
 * These tests make actual HTTP requests to the development site to verify
 * the webhook endpoint is working correctly end-to-end.
 *
 * @group integration
 * @group external-http
 */
class WebhookHttpTest extends TestCase {

	/**
	 * The base URL for the test site.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * The webhook endpoint URL.
	 *
	 * @var string
	 */
	private string $webhook_url;

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
		parent::setUp();

		// Load environment variables.
		if ( file_exists( __DIR__ . '/../../.env' ) ) {
			$dotenv = Dotenv::createImmutable( __DIR__ . '/../../' );
			$dotenv->load();
		}

		$this->base_url = getenv( 'WP_TEST_SITE_URL' ) ? getenv( 'WP_TEST_SITE_URL' ) : 'https://dev.foodgeek.dk';
		$this->webhook_url  = $this->base_url . '/webhook-kofi';
		$this->valid_token  = getenv( 'KOFI_VERIFICATION_TOKEN' ) ?: '';

		if ( empty( $this->valid_token ) ) {
			$this->markTestSkipped( 'KOFI_VERIFICATION_TOKEN not set in .env file' );
		}
	}

	/**
	 * Helper method to send a POST request to the webhook endpoint.
	 *
	 * @param array $payload The payload to send.
	 * @return array Response with 'status_code', 'body', and 'headers'.
	 */
	private function send_webhook_request( array $payload ): array {
		$ch = curl_init( $this->webhook_url );

		// Ko-fi sends data as application/x-www-form-urlencoded with data=<json_string>
		$post_data = 'data=' . rawurlencode( wp_json_encode( $payload ) );

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $post_data,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/x-www-form-urlencoded',
					'User-Agent: Ko-fi-Webhook-Test',
				),
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_TIMEOUT        => 30,
			)
		);

		$response    = curl_exec( $ch );
		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error       = curl_error( $ch );

		curl_close( $ch );

		if ( $error ) {
			$this->fail( "cURL error: {$error}" );
		}

		return array(
			'status_code' => $status_code,
			'body'        => $response,
			'decoded'     => json_decode( $response, true ),
		);
	}

	// ==================== VALID REQUEST TESTS ====================

	/**
	 * Test that a valid webhook request returns 200 OK.
	 */
	public function test_valid_webhook_returns_200(): void {
		$payload = array(
			'verification_token'      => $this->valid_token,
			'email'                   => 'http-test-' . time() . '@example.com',
			'tier_name'               => 'Gold',
			'amount'                  => 10.00,
			'currency'                => 'USD',
			'is_subscription_payment' => true,
			'message'                 => 'HTTP integration test',
		);

		$response = $this->send_webhook_request( $payload );

		$this->assertEquals( 200, $response['status_code'], 'Expected 200 status code for valid request' );
		$this->assertNotEmpty( $response['body'], 'Response body should not be empty' );

		// Check if response is JSON.
		$this->assertIsArray( $response['decoded'], 'Response should be valid JSON' );
		$this->assertArrayHasKey( 'success', $response['decoded'], 'Response should have success key' );
	}

	/**
	 * Test that a subscription payment is processed correctly.
	 */
	public function test_subscription_payment_processed(): void {
		$email = 'http-subscription-' . time() . '@example.com';

		$payload = array(
			'verification_token'      => $this->valid_token,
			'email'                   => $email,
			'tier_name'               => 'Silver',
			'amount'                  => 5.00,
			'currency'                => 'USD',
			'is_subscription_payment' => true,
		);

		$response = $this->send_webhook_request( $payload );

		$this->assertEquals( 200, $response['status_code'] );
		$this->assertTrue( $response['decoded']['success'] ?? false, 'Subscription should be processed successfully' );
	}

	// ==================== AUTHENTICATION TESTS ====================

	/**
	 * Test that missing verification token returns 400.
	 */
	public function test_missing_verification_token_returns_400(): void {
		$payload = array(
			'email'     => 'no-token@example.com',
			'tier_name' => 'Gold',
		);

		$response = $this->send_webhook_request( $payload );

		$this->assertEquals( 400, $response['status_code'], 'Missing token should return 400' );
	}

	/**
	 * Test that invalid verification token returns 401.
	 */
	public function test_invalid_verification_token_returns_401(): void {
		$payload = array(
			'verification_token' => 'invalid-token-12345',
			'email'              => 'invalid-token@example.com',
			'tier_name'          => 'Gold',
		);

		$response = $this->send_webhook_request( $payload );

		$this->assertEquals( 401, $response['status_code'], 'Invalid token should return 401' );
		$this->assertFalse( $response['decoded']['success'] ?? false, 'Request should not be successful' );
	}

	// ==================== VALIDATION TESTS ====================

	/**
	 * Test that missing email returns 400.
	 */
	public function test_missing_email_returns_400(): void {
		$payload = array(
			'verification_token' => $this->valid_token,
			'tier_name'          => 'Gold',
		);

		$response = $this->send_webhook_request( $payload );

		$this->assertEquals( 400, $response['status_code'], 'Missing email should return 400' );
	}

	/**
	 * Test that invalid email format returns 400.
	 */
	public function test_invalid_email_format_returns_400(): void {
		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'not-an-email',
			'tier_name'          => 'Gold',
		);

		$response = $this->send_webhook_request( $payload );

		$this->assertEquals( 400, $response['status_code'], 'Invalid email should return 400' );
	}

	/**
	 * Test that empty payload returns 400.
	 */
	public function test_empty_payload_returns_400(): void {
		$response = $this->send_webhook_request( array() );

		$this->assertEquals( 400, $response['status_code'], 'Empty payload should return 400' );
	}

	// ==================== EDGE CASE TESTS ====================

	/**
	 * Test webhook with minimal valid payload (only required fields).
	 */
	public function test_minimal_valid_payload(): void {
		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'http-minimal-' . time() . '@example.com',
		);

		$response = $this->send_webhook_request( $payload );

		$this->assertEquals( 200, $response['status_code'], 'Minimal payload should be accepted' );
	}

	/**
	 * Test webhook with special characters in email.
	 */
	public function test_special_characters_in_email(): void {
		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'test+special.' . time() . '@example.com',
			'tier_name'          => 'Gold',
		);

		$response = $this->send_webhook_request( $payload );

		$this->assertEquals( 200, $response['status_code'], 'Email with special characters should be accepted' );
	}

	/**
	 * Test that the endpoint exists and is accessible.
	 */
	public function test_endpoint_is_accessible(): void {
		$ch = curl_init( $this->webhook_url );

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_NOBODY         => true, // HEAD request.
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 10,
			)
		);

		curl_exec( $ch );
		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		// The endpoint should respond (even if it returns an error without POST data).
		$this->assertNotEquals( 0, $status_code, 'Endpoint should be accessible' );
		$this->assertNotEquals( 404, $status_code, 'Endpoint should not return 404' );
	}

	// ==================== SECURITY TESTS ====================

	/**
	 * Test that XSS attempts are sanitized.
	 */
	public function test_xss_sanitization(): void {
		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'xss-test-' . time() . '@example.com',
			'tier_name'          => '<script>alert("XSS")</script>',
			'message'            => '<img src=x onerror=alert(1)>',
		);

		$response = $this->send_webhook_request( $payload );

		// Should either process successfully (200) or be blocked by WAF (403).
		// Both indicate proper security handling.
		$this->assertContains( $response['status_code'], array( 200, 403 ), 'Should return 200 (sanitized) or 403 (blocked by WAF)' );
	}

	/**
	 * Test with very long strings.
	 */
	public function test_long_string_handling(): void {
		$payload = array(
			'verification_token' => $this->valid_token,
			'email'              => 'long-test-' . time() . '@example.com',
			'tier_name'          => str_repeat( 'A', 1000 ),
			'message'            => str_repeat( 'B', 5000 ),
		);

		$response = $this->send_webhook_request( $payload );

		// Should handle gracefully (either accept or reject, but not crash).
		$this->assertContains(
			$response['status_code'],
			array( 200, 400 ),
			'Should handle long strings gracefully'
		);
	}
}
