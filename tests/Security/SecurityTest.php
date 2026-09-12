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

namespace MembersForKofi\Tests\Security;

use MembersForKofi\Webhook\Webhook;
use MembersForKofi\Admin\AdminSettings;
use MembersForKofi\Tests\TestCase;

/**
 * Security-focused tests for the Members for Ko-fi plugin.
 *
 * Tests critical security boundaries including role protection,
 * authorization checks, and input sanitization.
 *
 * @package MembersForKofi
 */
class SecurityTest extends TestCase {

	/**
	 * Sets up the test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Set up test options.
		update_option(
			'members_for_kofi_options',
			array(
				'verification_token' => 'test-token-123',
				'only_subscriptions' => false,
				'tier_role_map'      => array(
					'Gold'   => 'editor',
					'Silver' => 'author',
					'Bronze' => 'contributor',
				),
				'default_role'       => 'subscriber',
				'enable_expiry'      => false,
				'role_expiry_days'   => 35,
			)
		);
	}

	/**
	 * Tests that administrator role is present in DISALLOWED_ROLES constant.
	 *
	 * This is a critical security test ensuring the administrator role
	 * cannot be assigned via webhook under any circumstances.
	 */
	public function test_administrator_role_is_disallowed(): void {
		$this->assertContains(
			'administrator',
			Webhook::DISALLOWED_ROLES,
			'Administrator role must be in DISALLOWED_ROLES for security'
		);
	}

	/**
	 * Tests that DISALLOWED_ROLES is not empty.
	 *
	 * At minimum, the administrator role must be disallowed.
	 */
	public function test_disallowed_roles_is_not_empty(): void {
		$this->assertNotEmpty(
			Webhook::DISALLOWED_ROLES,
			'DISALLOWED_ROLES must contain at least the administrator role'
		);
	}

	/**
	 * Tests that tier role map field rendering excludes administrator role.
	 *
	 * Verifies the admin UI does not allow administrator role selection
	 * in tier-to-role mappings.
	 */
	public function test_tier_role_map_excludes_administrator_role(): void {
		$settings = new AdminSettings();

		ob_start();
		$settings->render_tier_role_map_field();
		$output = ob_get_clean();

		// Check that administrator role is not available in the dropdown.
		$this->assertStringNotContainsString(
			'value="administrator"',
			$output,
			'Administrator role must not appear in tier-to-role mapping dropdown'
		);
	}

	/**
	 * Tests that default role field rendering excludes administrator role.
	 *
	 * Verifies the admin UI does not allow administrator role selection
	 * as the default fallback role.
	 */
	public function test_default_role_field_excludes_administrator_role(): void {
		$settings = new AdminSettings();

		ob_start();
		$settings->render_default_role_field();
		$output = ob_get_clean();

		// Check that administrator role is not available in the dropdown.
		$this->assertStringNotContainsString(
			'value="administrator"',
			$output,
			'Administrator role must not appear in default role dropdown'
		);
	}

	/**
	 * Tests that attempting to save administrator role in tier map is sanitized.
	 *
	 * Even if an attacker bypasses client-side validation, the sanitization
	 * function should not accept administrator role assignments.
	 *
	 * Note: This test verifies behavior, but the actual enforcement should be
	 * implemented in the sanitize_options method if not already present.
	 */
	public function test_sanitize_options_prevents_administrator_role_in_tier_map(): void {
		$settings = new AdminSettings();

		// Attempt to save administrator role in tier map (simulating malicious input).
		$malicious_input = array(
			'verification_token' => 'test-token',
			'tier_role_map'      => array(
				'tier' => array( 'VIP' ),
				'role' => array( 'administrator' ),
			),
			'default_role'       => 'subscriber',
			'enable_expiry'      => false,
			'role_expiry_days'   => 35,
		);

		$sanitized = $settings->sanitize_options( $malicious_input );

		// Verify administrator role is NOT in the sanitized tier map.
		$this->assertArrayHasKey( 'tier_role_map', $sanitized );
		foreach ( $sanitized['tier_role_map'] as $tier => $role ) {
			$this->assertNotEquals(
				'administrator',
				$role,
				'Sanitization must prevent administrator role in tier mappings'
			);
		}
	}

	/**
	 * Tests that attempting to save administrator as default role is prevented.
	 *
	 * Even if an attacker bypasses client-side validation, the sanitization
	 * function should not accept administrator as the default role.
	 */
	public function test_sanitize_options_prevents_administrator_as_default_role(): void {
		$settings = new AdminSettings();

		// Attempt to save administrator as default role (simulating malicious input).
		$malicious_input = array(
			'verification_token' => 'test-token',
			'tier_role_map'      => array(),
			'default_role'       => 'administrator',
			'enable_expiry'      => false,
			'role_expiry_days'   => 35,
		);

		$sanitized = $settings->sanitize_options( $malicious_input );

		// Verify administrator role is NOT set as default role.
		$this->assertNotEquals(
			'administrator',
			$sanitized['default_role'],
			'Sanitization must prevent administrator role as default role'
		);
	}

	/**
	 * Tests that webhook cannot assign administrator role even if requested.
	 *
	 * This integration test verifies the complete security boundary:
	 * even if Ko-fi were compromised and sent a payload requesting admin role,
	 * our plugin would not assign it.
	 *
	 * Note: This requires the resolve_role_from_tier method to check against
	 * DISALLOWED_ROLES, which should be verified or implemented.
	 */
	public function test_webhook_cannot_assign_administrator_role(): void {
		// Create a webhook instance.
		$webhook = new Webhook();

		// Configure tier map with administrator role (simulating configuration vulnerability).
		update_option(
			'members_for_kofi_options',
			array(
				'verification_token' => 'test-token-123',
				'tier_role_map'      => array(
					'VIP' => 'administrator', // This should never be possible, but testing defense in depth.
				),
				'default_role'       => 'subscriber',
			)
		);

		// Prepare webhook payload requesting VIP tier.
		$payload = array(
			'verification_token' => 'test-token-123',
			'email'              => 'attacker@example.com',
			'tier_name'          => 'VIP',
		);

		// Process the webhook.
		$response = $webhook->handle( null, $payload );

		// Verify the user was created.
		$user = get_user_by( 'email', 'attacker@example.com' );
		$this->assertInstanceOf( 'WP_User', $user );

		// CRITICAL: Verify user does NOT have administrator role.
		$this->assertNotContains(
			'administrator',
			$user->roles,
			'Webhook must NEVER assign administrator role, even if configured in tier map'
		);
	}

	/**
	 * Tests that verification token validation prevents unauthorized access.
	 *
	 * Security test ensuring webhook requests without valid token are rejected.
	 */
	public function test_webhook_rejects_invalid_verification_token(): void {
		$webhook = new Webhook();

		// Payload with wrong verification token.
		$payload = array(
			'verification_token' => 'wrong-token',
			'email'              => 'test@example.com',
			'tier_name'          => 'Gold',
		);

		$response = $webhook->handle( null, $payload );

		// Verify request is rejected with 401 Unauthorized.
		$this->assertEquals( 401, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->get_data() );
		$this->assertEquals( 'Unauthorized', $response->get_data()['error'] );
	}

	/**
	 * Tests that webhook rejects requests with missing verification token.
	 */
	public function test_webhook_rejects_missing_verification_token(): void {
		$webhook = new Webhook();

		// Payload without verification token.
		$payload = array(
			'email'     => 'test@example.com',
			'tier_name' => 'Gold',
		);

		$response = $webhook->handle( null, $payload );

		// Verify request is rejected with 400 Bad Request.
		$this->assertEquals( 400, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->get_data() );
	}

	/**
	 * Tests that email validation prevents malformed email addresses.
	 *
	 * Security test ensuring only valid email addresses are accepted.
	 */
	public function test_webhook_validates_email_format(): void {
		$webhook = new Webhook();

		// Test various invalid email formats.
		$invalid_emails = array(
			'not-an-email',
			'missing-at-sign.com',
			'@no-local-part.com',
			'no-domain@',
			'spaces in@email.com',
		);

		foreach ( $invalid_emails as $invalid_email ) {
			$payload = array(
				'verification_token' => 'test-token-123',
				'email'              => $invalid_email,
				'tier_name'          => 'Gold',
			);

			$response = $webhook->handle( null, $payload );

			// Verify request is rejected with 400 Bad Request.
			$this->assertEquals(
				400,
				$response->get_status(),
				"Invalid email '{$invalid_email}' should be rejected"
			);
		}
	}

	/**
	 * The log viewer must escape whatever is sitting in the log table.
	 *
	 * Replaces an earlier test of the same intent that asserted only a 200 and
	 * the existence of a user -- its own comment conceded it could not check
	 * sanitisation, so it proved nothing beyond "did not crash".
	 *
	 * The row is written directly rather than through the webhook on purpose:
	 * the point is that rendering is safe for whatever the table holds, not that
	 * one particular write path happens to clean its input first.
	 */
	public function test_log_viewer_escapes_stored_markup(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'members_for_kofi_request_logs';
		$wpdb->insert(
			$table,
			array(
				'email'       => 'stored-xss@example.com',
				'tier_name'   => '<script>alert(1)</script>',
				'payload'     => '{}',
				'status_code' => 200,
				'success'     => 1,
				'error'       => '<img src=x onerror=alert(2)>',
				'timestamp'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		delete_transient( 'members_for_kofi_total_request_logs' );

		ob_start();
		( new AdminSettings() )->render_request_logs_table();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'stored-xss@example.com', $output, 'The row should be rendered.' );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output, 'Stored markup must not be emitted raw.' );
		$this->assertStringNotContainsString( '<img src=x', $output, 'Stored markup must not be emitted raw.' );
		$this->assertStringContainsString( '&lt;script&gt;', $output, 'It should appear HTML-escaped instead.' );
	}

	/**
	 * Tests that the verification token never reaches the PHP error log.
	 *
	 * The webhook handler logs the whole payload as debug context, so the
	 * shared secret must be redacted before it is written out.
	 */
	public function test_verification_token_is_not_written_to_error_log(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'kofi-log-' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Redirecting error_log is the only way to capture logger output.
		$original = ini_set( 'error_log', $log_file );

		try {
			$webhook = new Webhook();
			$webhook->handle(
				null,
				array(
					'verification_token' => 'test-token-123',
					'email'              => 'log-redaction@example.com',
				)
			);
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring the original value.
			ini_set( 'error_log', false === $original ? '' : $original );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local temp file, not a URL.
		$contents = (string) file_get_contents( $log_file );
		wp_delete_file( $log_file );

		$this->assertNotSame( '', $contents, 'Expected the webhook to emit debug output to capture.' );
		$this->assertStringNotContainsString(
			'test-token-123',
			$contents,
			'Verification token must never appear in the error log.'
		);
		$this->assertStringContainsString(
			'[REDACTED]',
			$contents,
			'Expected the verification token to be redacted in logged context.'
		);
	}
}
