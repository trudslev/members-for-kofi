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

namespace MembersForKofi\Tests\Admin;

use MembersForKofi\Tests\TestCase;
use MembersForKofi\Admin\AdminSettings;
use MembersForKofi\Webhook\VerificationToken;

/**
 * Unit tests for the AdminSettings class.
 *
 * This class contains tests to verify the functionality of the
 * AdminSettings class, including sanitization and rendering methods.
 *
 * @package MembersForKofi\Tests\Admin
 */
class AdminSettingsTest extends TestCase {

	/**
	 * Instance of the AdminSettings class being tested.
	 *
	 * @var AdminSettings
	 */
	private AdminSettings $settings;

	/**
	 * Sets up the test environment before each test.
	 *
	 * Ensures necessary options are initialized and creates an instance
	 * of the AdminSettings class for testing.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Ensure the option exists so sanitize_options doesn't return false.
		if ( get_option( 'members_for_kofi_options' ) === false ) {
			add_option( 'members_for_kofi_options', array() );
		}

		$this->settings = new AdminSettings();

		// add_settings_error() collects into a global for the whole run, so a
		// test asserting "no errors" would otherwise see an earlier test's.
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
	}

	/**
	 * Tests the sanitize_options method with valid input.
	 *
	 * Verifies that the sanitize_options method correctly sanitizes
	 * and processes valid input data.
	 */
	public function test_sanitize_options_with_valid_input(): void {
		$input = array(
			'verification_token' => 'abc123',
			'only_subscriptions' => true,
			'tier_role_map'      => array(
				'tier' => array( 'Gold' ),
				'role' => array( 'editor' ),
			),
			'default_role'       => 'subscriber',
			'enable_expiry'      => true,
			'role_expiry_days'   => '14',
			'auto_clear_logs'    => true,
			'log_retention_days' => '30',
		);

		$sanitized = $this->settings->sanitize_options( $input );

		$this->assertSame( hash( 'sha256', 'abc123' ), $sanitized['verification_token_sha256'] );
		$this->assertArrayNotHasKey( 'verification_token', $sanitized, 'The plaintext token must never be stored.' );
		$this->assertTrue( $sanitized['only_subscriptions'] );
		$this->assertSame( array( 'Gold' => 'editor' ), $sanitized['tier_role_map'] );
		$this->assertSame( 'subscriber', $sanitized['default_role'] );
		$this->assertTrue( $sanitized['enable_expiry'] );
		$this->assertSame( 14, $sanitized['role_expiry_days'] );
		$this->assertTrue( $sanitized['auto_clear_logs'] );
		$this->assertSame( 30, $sanitized['log_retention_days'] );
	}

	/**
	 * Tests the sanitize_options method for tier-role map sanitization.
	 *
	 * Verifies that the sanitize_options method correctly processes
	 * and sanitizes the tier-role map input, removing empty values.
	 */
	public function test_tier_role_map_sanitization(): void {
		$input = array(
			'tier_role_map'      => array(
				'tier' => array( 'Gold', 'Silver', '' ),
				'role' => array( 'editor', 'author', '' ),
			),
			'verification_token' => 'xyz',
			'only_subscriptions' => true,
			'default_role'       => 'subscriber',
			'enable_expiry'      => true,
			'role_expiry_days'   => '30',
		);

		$sanitized = $this->settings->sanitize_options( $input );

		$expected_map = array(
			'Gold'   => 'editor',
			'Silver' => 'author',
		);

		$this->assertSame( $expected_map, $sanitized['tier_role_map'] );
	}

	/**
	 * Tests sanitization of logging settings with defaults.
	 *
	 * Verifies that auto_clear_logs defaults to true and log_retention_days
	 * defaults to 30 when not provided.
	 */
	public function test_sanitize_options_logging_defaults(): void {
		$input = array(
			'verification_token' => 'test123',
			'only_subscriptions' => false,
			'tier_role_map'      => array(),
			'default_role'       => 'subscriber',
			'enable_expiry'      => false,
			'role_expiry_days'   => '35',
		);

		$sanitized = $this->settings->sanitize_options( $input );

		$this->assertTrue( $sanitized['auto_clear_logs'], 'auto_clear_logs should default to true' );
		$this->assertSame( 30, $sanitized['log_retention_days'], 'log_retention_days should default to 30' );
	}

	/**
	 * Tests sanitization of logging settings with explicit false value.
	 *
	 * Verifies that auto_clear_logs can be disabled.
	 */
	public function test_sanitize_options_logging_disabled(): void {
		$input = array(
			'verification_token' => 'test123',
			'only_subscriptions' => false,
			'tier_role_map'      => array(),
			'default_role'       => 'subscriber',
			'enable_expiry'      => false,
			'role_expiry_days'   => '35',
			'auto_clear_logs'    => false,
			'log_retention_days' => '60',
		);

		$sanitized = $this->settings->sanitize_options( $input );

		$this->assertFalse( $sanitized['auto_clear_logs'], 'auto_clear_logs should be false when explicitly disabled' );
		$this->assertSame( 60, $sanitized['log_retention_days'], 'log_retention_days should be 60' );
	}

	/**
	 * Tests sanitization of invalid log retention days.
	 *
	 * Verifies that log_retention_days below 1 gets reset to default.
	 */
	public function test_sanitize_options_invalid_retention_days(): void {
		$input = array(
			'verification_token' => 'test123',
			'only_subscriptions' => false,
			'tier_role_map'      => array(),
			'default_role'       => 'subscriber',
			'enable_expiry'      => false,
			'role_expiry_days'   => '35',
			'auto_clear_logs'    => true,
			'log_retention_days' => '0',
		);

		$sanitized = $this->settings->sanitize_options( $input );

		$this->assertSame( 30, $sanitized['log_retention_days'], 'log_retention_days should reset to 30 when invalid' );
	}

	/**
	 * Tests sanitization of large log retention days value.
	 *
	 * Verifies that large values are accepted (no max limit).
	 */
	public function test_sanitize_options_large_retention_days(): void {
		$input = array(
			'verification_token' => 'test123',
			'only_subscriptions' => false,
			'tier_role_map'      => array(),
			'default_role'       => 'subscriber',
			'enable_expiry'      => false,
			'role_expiry_days'   => '35',
			'auto_clear_logs'    => true,
			'log_retention_days' => '9999',
		);

		$sanitized = $this->settings->sanitize_options( $input );

		$this->assertSame( 9999, $sanitized['log_retention_days'], 'log_retention_days should accept large values' );
	}

	/**
	 * A minimal valid settings submission.
	 *
	 * @param array $overrides Fields to change.
	 * @return array
	 */
	private function submission( array $overrides = array() ): array {
		return array_merge(
			array(
				'verification_token' => '',
				'only_subscriptions' => true,
				'default_role'       => 'subscriber',
				'enable_expiry'      => true,
				'role_expiry_days'   => '35',
				'auto_clear_logs'    => true,
				'log_retention_days' => '30',
			),
			$overrides
		);
	}

	/**
	 * The field is write-only, so a blank submission means "keep the saved
	 * token". Treating it as "clear" would break the webhook every time an
	 * admin saved any other setting.
	 */
	public function test_an_empty_token_field_keeps_the_saved_hash(): void {
		$this->write_options_raw( array( 'verification_token_sha256' => hash( 'sha256', 'saved-token' ) ) );

		$sanitized = $this->settings->sanitize_options( $this->submission() );

		$this->assertSame( hash( 'sha256', 'saved-token' ), $sanitized['verification_token_sha256'] );
		$this->assertEmpty( get_settings_errors( 'members_for_kofi_options' ), 'A blank field must not be an error when a token is saved.' );
	}

	/**
	 * Pasting a new token replaces the saved one.
	 */
	public function test_a_new_token_replaces_the_saved_hash(): void {
		$this->write_options_raw( array( 'verification_token_sha256' => hash( 'sha256', 'old-token' ) ) );

		$sanitized = $this->settings->sanitize_options( $this->submission( array( 'verification_token' => 'new-token' ) ) );

		$this->assertSame( hash( 'sha256', 'new-token' ), $sanitized['verification_token_sha256'] );
		$this->assertArrayNotHasKey( 'verification_token', $sanitized );
	}

	/**
	 * With nothing saved and nothing submitted there is no token at all, which
	 * is still an error, and the stored options are left alone.
	 */
	public function test_a_token_is_required_when_none_is_saved(): void {
		$this->write_options_raw( array( 'default_role' => 'subscriber' ) );

		$sanitized = $this->settings->sanitize_options( $this->submission() );

		$this->assertSame( array( 'default_role' => 'subscriber' ), $sanitized );
		$this->assertNotEmpty( get_settings_errors( 'members_for_kofi_options' ) );
	}

	/**
	 * A hash can only come from this code. Accepting one from the form would let
	 * a submission set a token without anyone knowing its plaintext -- and it
	 * would be hashed a second time into something no token could match.
	 */
	public function test_a_submitted_hash_is_ignored(): void {
		$this->write_options_raw( array( 'verification_token_sha256' => hash( 'sha256', 'saved-token' ) ) );

		$sanitized = $this->settings->sanitize_options(
			$this->submission( array( 'verification_token_sha256' => str_repeat( 'a', 64 ) ) )
		);

		$this->assertSame( hash( 'sha256', 'saved-token' ), $sanitized['verification_token_sha256'] );
	}

	/**
	 * A site still holding a plaintext token (the fallback case) is migrated by
	 * the next save, even if the field is left blank.
	 */
	public function test_a_blank_save_hashes_a_still_plaintext_token(): void {
		$this->write_options_raw( array( 'verification_token' => 'legacy-token' ) );

		$sanitized = $this->settings->sanitize_options( $this->submission() );

		$this->assertSame( hash( 'sha256', 'legacy-token' ), $sanitized['verification_token_sha256'] );
		$this->assertArrayNotHasKey( 'verification_token', $sanitized );
	}

	/**
	 * Saving resolves a conflict: the output never carries the plaintext key.
	 */
	public function test_saving_a_token_clears_a_conflict(): void {
		$this->write_options_raw(
			array(
				'verification_token_sha256' => hash( 'sha256', 'hashed-token' ),
				'verification_token'        => 'other-token',
			)
		);

		$sanitized = $this->settings->sanitize_options( $this->submission( array( 'verification_token' => 'hashed-token' ) ) );

		$this->assertFalse( VerificationToken::has_conflict( $sanitized ) );
		$this->assertArrayNotHasKey( 'verification_token', $sanitized );
	}

	/**
	 * A token pasted with stray whitespace is stored trimmed.
	 *
	 * This is the rule as it stands, not a new one: sanitize_text_field()
	 * trims. It matches Ko-fi's real traffic, whose form-encoded body goes
	 * through the same function (see DonationWebhookTest). A caller handing
	 * handle() an array directly, or the REST route, is NOT trimmed -- so
	 * nothing here claims that a padded token verifies on every path.
	 */
	public function test_a_padded_token_is_stored_trimmed(): void {
		$sanitized = $this->settings->sanitize_options( $this->submission( array( 'verification_token' => "  padded-token \t\n" ) ) );

		$this->assertSame( hash( 'sha256', 'padded-token' ), $sanitized['verification_token_sha256'] );
	}

	/**
	 * Saving through the real Settings API (update_option with the sanitizer
	 * registered) stores only the hash.
	 */
	public function test_saving_through_the_settings_api_stores_only_the_hash(): void {
		$this->settings->register_settings();

		try {
			update_option( 'members_for_kofi_options', $this->submission( array( 'verification_token' => 'api-token' ) ) );
		} finally {
			// Left registered, the sanitizer would rewrite every later test's
			// option fixtures for the rest of the run.
			unregister_setting( 'members_for_kofi_options', 'members_for_kofi_options' );
		}

		$stored = get_option( 'members_for_kofi_options' );
		$this->assertSame( hash( 'sha256', 'api-token' ), $stored['verification_token_sha256'] );
		$this->assertArrayNotHasKey( 'verification_token', $stored );
	}

	/**
	 * The sanitizer also sees internal writes to the option -- the migration's
	 * own update_option() when it runs in an admin request. Feeding it the
	 * already-migrated options must hand them back unchanged in substance.
	 */
	public function test_sanitizing_already_hashed_options_keeps_the_hash(): void {
		$hashed = array(
			'verification_token_sha256' => hash( 'sha256', 'kept-token' ),
			'only_subscriptions'        => false,
			'tier_role_map'             => array( 'Gold' => 'editor' ),
			'default_role'              => 'subscriber',
			'enable_expiry'             => true,
			'role_expiry_days'          => 35,
			'auto_clear_logs'           => true,
			'log_retention_days'        => 30,
		);
		$this->write_options_raw( array( 'verification_token' => 'kept-token' ) );

		$this->assertSame( $hashed, $this->settings->sanitize_options( $hashed ) );
	}
}
