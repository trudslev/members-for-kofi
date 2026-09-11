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

use MembersForKofi\Admin\AdminSettings;
use MembersForKofi\Tests\TestCase;

/**
 * Unit tests for the AdminSettings class render methods.
 *
 * @package MembersForKofi
 */
class AdminSettingsRenderTest extends TestCase {

	/**
	 * Instance of the AdminSettings class used for testing.
	 *
	 * @var AdminSettings
	 */
	protected AdminSettings $settings;

	/**
	 * Sets up the test environment before each test.
	 *
	 * Initializes the AdminSettings instance and updates the options.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new AdminSettings();

		update_option(
			'members_for_kofi_options',
			array(
				'verification_token' => 'sample-token',
				'only_subscriptions' => true,
				'tier_role_map'      => array( 'Gold' => 'editor' ),
				'default_role'       => 'subscriber',
				'enable_expiry'      => true,
				'role_expiry_days'   => 42,
			)
		);
	}

	/**
	 * Captures the output of a render function.
	 *
	 * @param callable $render_function The render function to capture output from.
	 * @return string The captured output as a string.
	 */
	private function capture_render( callable $render_function ): string {
		ob_start();
		$render_function();
		return ob_get_clean();
	}

	/**
	 * Tests the rendering of the verification token field.
	 *
	 * Ensures that the rendered output contains the expected input field
	 * for the verification token with the correct name attribute.
	 */
	public function test_render_verification_token_field(): void {
		$output = $this->capture_render( array( $this->settings, 'render_verification_token_field' ) );
		$this->assertStringContainsString( 'name="members_for_kofi_options[verification_token]"', $output );
	}

	/**
	 * Tests the rendering of the only subscriptions field.
	 *
	 * Ensures that the rendered output contains the expected input field
	 * for the only subscriptions option with the correct name attribute.
	 */
	public function test_render_only_subscriptions_field(): void {
		$output = $this->capture_render( array( $this->settings, 'render_only_subscriptions_field' ) );

		$this->assertStringContainsString( 'name="members_for_kofi_options[only_subscriptions]"', $output );
		$this->assertStringContainsString( 'checked', $output, 'The stored "true" value should render as checked.' );

		$options                       = (array) get_option( 'members_for_kofi_options', array() );
		$options['only_subscriptions'] = false;
		update_option( 'members_for_kofi_options', $options );

		$output = $this->capture_render( array( $this->settings, 'render_only_subscriptions_field' ) );
		$this->assertStringNotContainsString( 'checked', $output, 'A stored "false" value must not render as checked.' );
	}

	/**
	 * Tests the rendering of the tier role map field.
	 *
	 * Ensures that the rendered output contains the expected table element
	 * for the tier role map configuration.
	 */
	public function test_render_tier_role_map_field(): void {
		$output = $this->capture_render( array( $this->settings, 'render_tier_role_map_field' ) );

		$this->assertStringContainsString( '<table', $output );
		$this->assertStringContainsString( 'value="Gold"', $output, 'The stored tier name should be rendered.' );
		$this->assertMatchesRegularExpression(
			'/<option value="editor"[^>]*selected/',
			$output,
			'The role mapped to the stored tier should be pre-selected.'
		);
	}

	/**
	 * Tests the rendering of the default role field.
	 *
	 * Ensures that the rendered output contains the expected input field
	 * for the default role option with the correct name attribute.
	 */
	public function test_render_default_role_field(): void {
		$output = $this->capture_render( array( $this->settings, 'render_default_role_field' ) );

		$this->assertStringContainsString( 'name="members_for_kofi_options[default_role]"', $output );
		$this->assertMatchesRegularExpression(
			'/<option value="subscriber"[^>]*selected/',
			$output,
			'The stored default role should be pre-selected.'
		);
	}

	/**
	 * Tests the rendering of the expiry toggle field.
	 *
	 * Ensures that the rendered output contains the expected input field
	 * for the enable expiry option with the correct name attribute.
	 */
	public function test_render_expiry_toggle_field(): void {
		$output = $this->capture_render( array( $this->settings, 'render_expiry_toggle_field' ) );

		$this->assertStringContainsString( 'name="members_for_kofi_options[enable_expiry]"', $output );
		$this->assertStringContainsString( 'checked', $output, 'The stored "true" value should render as checked.' );
	}

	/**
	 * Tests the rendering of the role expiry field.
	 *
	 * Ensures that the rendered output contains the expected input field
	 * for the role expiry days option with the correct name attribute.
	 */
	public function test_render_role_expiry_field(): void {
		$output = $this->capture_render( array( $this->settings, 'render_role_expiry_field' ) );

		$this->assertMatchesRegularExpression( '/<input[^>]*name="members_for_kofi_options\[role_expiry_days\]"/', $output );
		$this->assertStringContainsString( 'value="42"', $output, 'The stored expiry period should be rendered.' );
	}

	/**
	 * Tests the rendering of the logging field.
	 *
	 * Ensures that the rendered output contains the expected input field
	 * for the log enabled option with the correct name attribute.
	 */
	// Logging fields removed; corresponding render tests dropped.

	/**
	 * The token field must escape the stored value, not echo it raw.
	 *
	 * Whatever is stored is printed straight back into an HTML attribute, so a
	 * value containing a quote would otherwise break out of it. Asserting only
	 * that the field has the right `name` (as the other render tests do) leaves
	 * this entirely uncovered.
	 */
	public function test_render_verification_token_field_escapes_the_value(): void {
		$options                       = (array) get_option( 'members_for_kofi_options', array() );
		$options['verification_token'] = 'tok"><script>alert(1)</script>';
		update_option( 'members_for_kofi_options', $options );

		$output = $this->capture_render( array( $this->settings, 'render_verification_token_field' ) );

		$this->assertStringNotContainsString(
			'tok"><script>',
			$output,
			'The stored token must never reach the attribute unescaped.'
		);
		$this->assertStringContainsString(
			'tok&quot;&gt;&lt;script&gt;',
			$output,
			'The value should be present in escaped form.'
		);
	}
}
