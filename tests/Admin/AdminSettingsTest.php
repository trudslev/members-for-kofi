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

use PHPUnit\Framework\TestCase;
use MembersForKofi\Admin\AdminSettings;

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

		$this->assertSame( 'abc123', $sanitized['verification_token'] );
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
}
