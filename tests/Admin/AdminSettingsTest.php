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
 * @package KofiMembers
 */

namespace KofiMembers\Tests\Admin;

use PHPUnit\Framework\TestCase;
use KofiMembers\Admin\AdminSettings;

/**
 * Unit tests for the AdminSettings class.
 *
 * This class contains tests to verify the functionality of the
 * AdminSettings class, including sanitization and rendering methods.
 *
 * @package KofiMembers\Tests\Admin
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
		if ( get_option( 'kofi_members_options' ) === false ) {
			add_option( 'kofi_members_options', array() );
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
			'log_enabled'        => true,
			'log_level'          => 'warning',
		);

		$sanitized = $this->settings->sanitize_options( $input );

		$this->assertSame( 'abc123', $sanitized['verification_token'] );
		$this->assertTrue( $sanitized['only_subscriptions'] );
		$this->assertSame( array( 'Gold' => 'editor' ), $sanitized['tier_role_map'] );
		$this->assertSame( 'subscriber', $sanitized['default_role'] );
		$this->assertTrue( $sanitized['enable_expiry'] );
		$this->assertSame( 14, $sanitized['role_expiry_days'] );
		$this->assertTrue( $sanitized['log_enabled'] );
		$this->assertSame( 'warning', $sanitized['log_level'] );
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
	 * Tests the renderLogLevelField method.
	 *
	 * Verifies that the renderLogLevelField method outputs the correct
	 * HTML for the log level select field, including the selected option.
	 */
	public function test_render_log_level_field_outputs_select(): void {
		update_option(
			'kofi_members_options',
			array(
				'log_level' => 'warning',
			)
		);

		$settings = new AdminSettings();

		ob_start();
		$settings->render_log_level_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<select name="kofi_members_options[log_level]">', $output );
		$this->assertStringContainsString( '<option value="warning" selected=\'selected\'>', $output );
		$this->assertStringContainsString( 'Minimum severity of log messages to record.', $output );
	}
}
