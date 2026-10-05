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
	 * The field is write-only: neither a stored plaintext token nor its hash
	 * may ever reach the page. This replaces the old escaping test -- a value
	 * that is never rendered cannot break out of its attribute.
	 */
	public function test_the_stored_token_is_never_rendered(): void {
		$legacy = 'tok"><script>alert(1)</script>';
		$this->write_options_raw( array( 'verification_token' => $legacy ) );

		$output = $this->capture_render( array( $this->settings, 'render_verification_token_field' ) );

		$this->assertStringContainsString( 'name="members_for_kofi_options[verification_token]" value=""', $output );
		$this->assertStringNotContainsString( 'alert(1)', $output );
		$this->assertStringNotContainsString( 'tok&quot;', $output );
		$this->assertStringNotContainsString( hash( 'sha256', $legacy ), $output );
	}

	/**
	 * With a hash saved, the page says so, shows an eight-character
	 * fingerprint, and never the hash itself.
	 */
	public function test_a_saved_token_shows_its_fingerprint_only(): void {
		$hash = hash( 'sha256', 'fingerprinted-token' );
		$this->write_options_raw( array( 'verification_token_sha256' => $hash ) );

		$output = $this->capture_render( array( $this->settings, 'render_verification_token_field' ) );

		$this->assertStringContainsString( '<code>' . substr( $hash, 0, 8 ) . '</code>', $output );
		$this->assertStringNotContainsString( substr( $hash, 0, 9 ), $output );
		$this->assertStringContainsString( 'A token is saved', $output );
	}

	/**
	 * Without a token the page says none is set, and shows no fingerprint.
	 */
	public function test_no_token_says_so(): void {
		$this->write_options_raw( array( 'default_role' => 'subscriber' ) );

		$output = $this->capture_render( array( $this->settings, 'render_verification_token_field' ) );

		$this->assertStringContainsString( 'No token set yet', $output );
		$this->assertStringNotContainsString( 'fingerprint:', $output );
	}

	/**
	 * A conflicting hash and plaintext are flagged under the field.
	 */
	public function test_a_conflict_is_flagged_on_the_field(): void {
		$this->write_options_raw(
			array(
				'verification_token_sha256' => hash( 'sha256', 'one' ),
				'verification_token'        => 'two',
			)
		);

		$output = $this->capture_render( array( $this->settings, 'render_verification_token_field' ) );

		$this->assertStringContainsString( 'two different verification tokens are stored', $output );
		$this->assertStringNotContainsString( '>two<', $output );
	}

	/**
	 * The conflict notice shows on admin screens for administrators only, and
	 * only while there is a conflict.
	 */
	public function test_the_conflict_notice_is_shown_to_admins_only_while_it_applies(): void {
		$conflict = array(
			'verification_token_sha256' => hash( 'sha256', 'one' ),
			'verification_token'        => 'two',
		);
		$previous = get_current_user_id();

		try {
			wp_set_current_user( $this->create_user( array( 'role' => 'administrator' ) ) );

			$this->write_options_raw( $conflict );
			$this->assertStringContainsString( 'notice-error', $this->capture_render( array( AdminSettings::class, 'render_token_conflict_notice' ) ) );

			$this->write_options_raw( array( 'verification_token_sha256' => hash( 'sha256', 'one' ) ) );
			$this->assertSame( '', $this->capture_render( array( AdminSettings::class, 'render_token_conflict_notice' ) ) );

			wp_set_current_user( $this->create_user( array( 'role' => 'editor' ) ) );
			$this->write_options_raw( $conflict );
			$this->assertSame( '', $this->capture_render( array( AdminSettings::class, 'render_token_conflict_notice' ) ) );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * The notice is hooked where WordPress prints admin notices.
	 */
	public function test_the_conflict_notice_is_hooked(): void {
		$this->assertNotFalse( has_action( 'admin_notices', array( AdminSettings::class, 'render_token_conflict_notice' ) ) );
	}

	/**
	 * Settings API messages are printed on the page.
	 *
	 * This is a top-level menu page, where WordPress does not print them by
	 * itself: before 1.2.0 a rejected save -- a missing token, a disallowed
	 * role -- was dropped without a word.
	 */
	public function test_the_settings_page_shows_validation_errors(): void {
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
		add_settings_error( 'members_for_kofi_options', 'members_for_kofi_options_error', 'Verification Token is required.', 'error' );

		try {
			$output = $this->capture_render( array( $this->settings, 'render_settings_page' ) );
		} finally {
			$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
		}

		$this->assertStringContainsString( 'Verification Token is required.', $output );
		$this->assertStringContainsString( 'notice-error', $output );
	}

	/**
	 * The URL given to Ko-fi works in the site's permalink mode.
	 */
	public function test_the_webhook_url_follows_the_permalink_mode(): void {
		$structure = get_option( 'permalink_structure' );

		try {
			update_option( 'permalink_structure', '' );
			$this->assertSame( home_url( '/?kofi_webhook=1' ), AdminSettings::webhook_url() );
			$this->assertStringContainsString( 'kofi_webhook=1', $this->capture_render( array( $this->settings, 'render_verification_token_field' ) ) );

			update_option( 'permalink_structure', '/%postname%/' );
			$this->assertSame( home_url( '/webhook-kofi/' ), AdminSettings::webhook_url() );
		} finally {
			update_option( 'permalink_structure', $structure );
		}
	}

	/**
	 * An overdue scheduled task is reported; an on-time one is not.
	 */
	public function test_overdue_scheduled_tasks_are_reported(): void {
		$hook = 'kofi_members_check_expired_roles';
		$was  = wp_next_scheduled( $hook );

		try {
			wp_clear_scheduled_hook( $hook );
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
			$this->assertSame( '', $this->capture_render( array( $this->settings, 'render_cron_warning' ) ) );

			wp_clear_scheduled_hook( $hook );
			wp_schedule_event( time() - 3 * DAY_IN_SECONDS, 'daily', $hook );
			$this->assertStringContainsString( 'Scheduled tasks are not running', $this->capture_render( array( $this->settings, 'render_cron_warning' ) ) );
		} finally {
			wp_clear_scheduled_hook( $hook );
			wp_schedule_event( false !== $was ? $was : time(), 'daily', $hook );
		}
	}

	/**
	 * An account left by Ko-fi's test button is pointed out to administrators
	 * on the screens where they would act on it, and only while it exists.
	 */
	public function test_a_leftover_kofi_test_account_is_pointed_out(): void {
		$previous = get_current_user_id();
		$test_id  = $this->create_user(
			array(
				'user_email' => \MembersForKofi\Webhook\Webhook::KOFI_TEST_EMAIL,
				'role'       => 'subscriber',
			)
		);

		try {
			wp_set_current_user( $this->create_user( array( 'role' => 'administrator' ) ) );

			$notice = $this->capture_render(
				static function () {
					AdminSettings::render_test_account_notice( 'dashboard' );
				}
			);
			$this->assertStringContainsString( 'Send test', $notice );
			$this->assertStringContainsString( 'Subscriber', $notice );
			$this->assertStringContainsString( 'user_id=' . $test_id, $notice );

			$this->assertSame(
				'',
				$this->capture_render(
					static function () {
						AdminSettings::render_test_account_notice( 'edit-post' );
					}
				),
				'Not on unrelated screens.'
			);

			wp_set_current_user( $this->create_user( array( 'role' => 'editor' ) ) );
			$this->assertSame(
				'',
				$this->capture_render(
					static function () {
						AdminSettings::render_test_account_notice( 'dashboard' );
					}
				),
				'Only for those who can delete users.'
			);

			wp_set_current_user( $this->create_user( array( 'role' => 'administrator' ) ) );
			wp_delete_user( $test_id );
			$this->assertSame(
				'',
				$this->capture_render(
					static function () {
						AdminSettings::render_test_account_notice( 'users' );
					}
				),
				'Gone once the account is deleted.'
			);
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * The notice is hooked.
	 */
	public function test_the_test_account_notice_is_hooked(): void {
		$this->assertNotFalse( has_action( 'admin_notices', array( AdminSettings::class, 'render_test_account_notice' ) ) );
	}

	/**
	 * The notice works when WordPress itself fires admin_notices, which
	 * passes callbacks an empty string rather than nothing: the notice once
	 * read that as a screen called '' and never appeared on a real page.
	 */
	public function test_the_test_account_notice_appears_when_wordpress_fires_it(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		$previous = get_current_user_id();
		$this->create_user( array( 'user_email' => \MembersForKofi\Webhook\Webhook::KOFI_TEST_EMAIL ) );

		try {
			wp_set_current_user( $this->create_user( array( 'role' => 'administrator' ) ) );
			set_current_screen( 'dashboard' );

			$output = $this->capture_render(
				static function () {
					do_action( 'admin_notices' );
				}
			);

			$this->assertStringContainsString( 'Send test', $output );
		} finally {
			set_current_screen( 'front' );
			wp_set_current_user( $previous );
		}
	}
}
