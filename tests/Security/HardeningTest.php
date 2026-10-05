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
 * @package Ko-fiMembers
 */

namespace MembersForKofi\Tests\Security;

use MembersForKofi\Admin\AdminSettings;
use MembersForKofi\Logging\RequestLogger;
use MembersForKofi\Tests\TestCase;
use MembersForKofi\Webhook\Webhook;

/**
 * Covers the hardening items from the 2026-09 security audit.
 *
 * Audit issues #3 (unauthenticated database writes), #6 (non-constant-time
 * token comparison), #7 (unclamped
 * rows_per_page) and #8 (roles that do not exist being assigned).
 */
class HardeningTest extends TestCase {

	/**
	 * Configures the plugin with a known token.
	 *
	 * @param string $token Verification token to store.
	 * @param array  $extra Extra option overrides.
	 * @return void
	 */
	private function configure( string $token, array $extra = array() ): void {
		// Stored as the plugin itself stores it since 1.2.0: the hash only, so
		// these tests exercise the hashed path, not the plaintext fallback.
		$this->write_options_raw(
			array_merge(
				array(
					'verification_token_sha256' => hash( 'sha256', $token ),
					'only_subscriptions'        => false,
					'default_role'              => 'subscriber',
					'tier_role_map'             => array(),
				),
				$extra
			)
		);
	}

	// ------------------------------------------------------------------
	// #6 - token comparison
	// ------------------------------------------------------------------

	/**
	 * The correct token is still accepted.
	 */
	public function test_correct_token_is_accepted(): void {
		$this->configure( 'correct-horse-battery-staple' );

		$webhook  = new Webhook();
		$response = $webhook->handle(
			null,
			array(
				'verification_token' => 'correct-horse-battery-staple',
				'email'              => 'ok@example.com',
			)
		);

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * A token sharing a long prefix with the real one is still rejected.
	 *
	 * The point of hash_equals() is that this comparison takes the same time
	 * as one failing on the first byte; what the test can pin down is that a
	 * near-miss is refused rather than accidentally accepted.
	 */
	public function test_token_matching_only_a_prefix_is_rejected(): void {
		$this->configure( 'correct-horse-battery-staple' );

		$webhook  = new Webhook();
		$response = $webhook->handle(
			null,
			array(
				'verification_token' => 'correct-horse-battery-stapleX',
				'email'              => 'nope@example.com',
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A token that is a strict prefix of the real one is rejected.
	 */
	public function test_token_that_is_a_truncated_prefix_is_rejected(): void {
		$this->configure( 'correct-horse-battery-staple' );

		$webhook  = new Webhook();
		$response = $webhook->handle(
			null,
			array(
				'verification_token' => 'correct-horse',
				'email'              => 'nope@example.com',
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A non-string token must not blow up the comparison.
	 *
	 * Non-string input matters here: hash_equals() raises a TypeError on it,
	 * so without the guard an array would be a fatal error on a public,
	 * unauthenticated endpoint.
	 */
	public function test_non_string_token_is_rejected_without_fatal(): void {
		$this->configure( 'correct-horse-battery-staple' );

		$webhook  = new Webhook();
		$response = $webhook->handle(
			null,
			array(
				'verification_token' => array( 'unexpected' ),
				'email'              => 'nope@example.com',
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	// ------------------------------------------------------------------
	// #8 - role must actually exist
	// ------------------------------------------------------------------

	/**
	 * A mapping pointing at a role WordPress does not have assigns nothing.
	 *
	 * Otherwise add_role() writes the unknown slug into the user's capability
	 * meta, where it grants nothing until something creates a role with that
	 * slug -- at which point it silently starts granting.
	 */
	public function test_role_that_does_not_exist_is_not_assigned(): void {
		$this->configure(
			'tok',
			array( 'default_role' => 'no-such-role-here' )
		);

		$webhook  = new Webhook();
		$response = $webhook->handle(
			null,
			array(
				'verification_token' => 'tok',
				'email'              => 'ghost@example.com',
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$user = get_user_by( 'email', 'ghost@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );

		// $user->roles is the wrong place to look: get_role_caps() already
		// filters out slugs WordPress has no role object for, so it stays clean
		// whether or not the slug was assigned. The pollution lands in the raw
		// capabilities meta, which is what would start granting capabilities the
		// moment something registered a role with this slug.
		global $wpdb;
		$capabilities = get_user_meta( $user->ID, $wpdb->prefix . 'capabilities', true );

		$this->assertIsArray( $capabilities );
		$this->assertArrayNotHasKey(
			'no-such-role-here',
			$capabilities,
			'A role WordPress does not define must never reach the capability meta'
		);
	}

	/**
	 * A role that does exist is still assigned.
	 */
	public function test_role_that_exists_is_assigned(): void {
		$this->configure( 'tok', array( 'default_role' => 'editor' ) );

		$webhook = new Webhook();
		$webhook->handle(
			null,
			array(
				'verification_token' => 'tok',
				'email'              => 'real-role@example.com',
			)
		);

		$user = get_user_by( 'email', 'real-role@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertContains( 'editor', $user->roles );
	}

	// ------------------------------------------------------------------
	// #7 - rows_per_page
	// ------------------------------------------------------------------

	/**
	 * Page sizes the picker offers are kept as-is.
	 */
	public function test_offered_page_sizes_are_accepted(): void {
		$settings = new AdminSettings();

		foreach ( AdminSettings::ROWS_PER_PAGE_OPTIONS as $size ) {
			$this->assertSame( $size, $settings->sanitize_rows_per_page( $size ) );
		}
	}

	/**
	 * Zero must not reach the division that paginates the table.
	 *
	 * `ceil( $total_logs / 0 )` is a DivisionByZeroError on PHP 8 - a fatal
	 * error and an HTTP 500 on the logs screen.
	 */
	public function test_zero_page_size_falls_back_to_the_default(): void {
		$settings = new AdminSettings();

		$this->assertSame(
			AdminSettings::DEFAULT_ROWS_PER_PAGE,
			$settings->sanitize_rows_per_page( 0 )
		);
	}

	/**
	 * An enormous page size must not reach the LIMIT clause.
	 */
	public function test_huge_page_size_falls_back_to_the_default(): void {
		$settings = new AdminSettings();

		$this->assertSame(
			AdminSettings::DEFAULT_ROWS_PER_PAGE,
			$settings->sanitize_rows_per_page( 999999999 )
		);
	}

	/**
	 * Values that are not offered are refused rather than squeezed into range.
	 *
	 * @dataProvider provide_unoffered_page_sizes
	 *
	 * @param mixed $raw Value to sanitize.
	 * @return void
	 */
	public function test_unoffered_page_sizes_fall_back_to_the_default( $raw ): void {
		$settings = new AdminSettings();

		$this->assertSame(
			AdminSettings::DEFAULT_ROWS_PER_PAGE,
			$settings->sanitize_rows_per_page( $raw )
		);
	}

	/**
	 * Values a picker would never produce.
	 *
	 * @return array<string, array<mixed>>
	 */
	public function provide_unoffered_page_sizes(): array {
		return array(
			'negative'      => array( -5 ),
			'between sizes' => array( 11 ),
			'just over max' => array( 101 ),
			'non numeric'   => array( 'all' ),
			'empty string'  => array( '' ),
			'sql injection' => array( '10; DROP TABLE wp_users' ),
		);
	}

	// ------------------------------------------------------------------
	// #3 - unauthenticated database writes
	// ------------------------------------------------------------------

	/**
	 * Counts rows in the request log.
	 *
	 * @return int
	 */
	private function request_log_count(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'members_for_kofi_request_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Sends a request that will not authenticate.
	 *
	 * @return int HTTP status.
	 */
	private function send_bad_request(): int {
		$webhook = new Webhook();

		return $webhook->handle(
			null,
			array(
				'verification_token' => 'wrong',
				'email'              => 'attacker@example.com',
			)
		)->get_status();
	}

	/**
	 * Lowers the failure limit so the throttle can be reached quickly.
	 *
	 * @param int $limit Limit to apply.
	 * @return callable The filter, for removal.
	 */
	private function set_failure_limit( int $limit ): callable {
		$filter = static function () use ( $limit ) {
			return $limit;
		};

		add_filter( 'members_for_kofi_webhook_failure_limit', $filter );

		return $filter;
	}

	/**
	 * A flood of unauthenticated requests stops writing rows.
	 *
	 * The payload column is TEXT, so before this an unauthenticated caller
	 * could push roughly 64 KB per request into the table indefinitely.
	 */
	public function test_repeated_failures_stop_writing_rows(): void {
		$filter = $this->set_failure_limit( 3 );

		try {
			for ( $i = 0; $i < 3; $i++ ) {
				$this->assertSame( 401, $this->send_bad_request(), "Request {$i} should still be answered normally" );
			}

			$rows_before = $this->request_log_count();

			for ( $i = 0; $i < 10; $i++ ) {
				$this->assertSame( 429, $this->send_bad_request(), 'Expected the endpoint to shed the request' );
			}

			$this->assertSame(
				$rows_before,
				$this->request_log_count(),
				'A shed request must not write to the database'
			);
		} finally {
			remove_filter( 'members_for_kofi_webhook_failure_limit', $filter );
		}
	}

	/**
	 * A genuine donation is never throttled, whatever else has been happening.
	 *
	 * This is the property that matters operationally: throttling a real Ko-fi
	 * payment would silently lose it. Only failures are counted, and the token
	 * is checked before the limit is consulted.
	 */
	public function test_a_valid_donation_is_never_throttled(): void {
		$this->configure( 'live-token' );
		$filter = $this->set_failure_limit( 2 );

		try {
			// Bury the endpoint in failures first.
			for ( $i = 0; $i < 20; $i++ ) {
				$this->send_bad_request();
			}

			$webhook  = new Webhook();
			$response = $webhook->handle(
				null,
				array(
					'verification_token' => 'live-token',
					'email'              => 'real-donor@example.com',
					'tier_name'          => 'Gold',
					'amount'             => '25.00',
				)
			);

			$this->assertSame(
				200,
				$response->get_status(),
				'A donation with the correct token must go through even while failures are being shed'
			);

			$this->assertInstanceOf(
				\WP_User::class,
				get_user_by( 'email', 'real-donor@example.com' ),
				'The donor account must still be created'
			);
		} finally {
			remove_filter( 'members_for_kofi_webhook_failure_limit', $filter );
		}
	}

	/**
	 * A zero limit turns the throttle off entirely.
	 */
	public function test_throttling_can_be_disabled_by_filter(): void {
		$filter = $this->set_failure_limit( 0 );

		try {
			for ( $i = 0; $i < 12; $i++ ) {
				$this->assertSame( 401, $this->send_bad_request() );
			}
		} finally {
			remove_filter( 'members_for_kofi_webhook_failure_limit', $filter );
		}
	}

	/**
	 * An oversized payload cannot decide how much of the table it occupies.
	 */
	public function test_stored_payload_is_capped(): void {
		$this->configure( 'cap-token' );

		$webhook = new Webhook();
		$webhook->handle(
			null,
			array(
				'verification_token' => 'cap-token',
				'email'              => 'huge@example.com',
				'message'            => str_repeat( 'A', 60000 ),
			)
		);

		global $wpdb;
		$table = $wpdb->prefix . 'members_for_kofi_request_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		$payload = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT payload FROM `{$table}` WHERE email = %s ORDER BY id DESC LIMIT 1", 'huge@example.com' )
		);

		$this->assertNotSame( '', $payload, 'Expected the donation to be logged' );
		$this->assertLessThanOrEqual(
			RequestLogger::MAX_PAYLOAD_LENGTH + 32,
			strlen( $payload ),
			'The stored payload must be capped'
		);
	}

	/**
	 * Rendering the logs table with a hostile page size does not fatal.
	 *
	 * This is the end the user actually hits: a 500 on the logs screen.
	 */
	public function test_rendering_logs_with_zero_page_size_does_not_fatal(): void {
		$settings = new AdminSettings();

		ob_start();
		$settings->render_user_logs_table( null, 0 );
		$output = (string) ob_get_clean();

		$this->assertNotSame( '', $output, 'Expected the logs table to render' );
	}

	// ------------------------------------------------------------------
	// Hashed token storage (1.2.0)
	// ------------------------------------------------------------------

	/**
	 * The error recorded for the most recent request log row.
	 *
	 * @return string
	 */
	private function last_logged_error(): string {
		global $wpdb;

		$table = $wpdb->prefix . 'members_for_kofi_request_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, test-only read.
		return (string) $wpdb->get_var( "SELECT `error` FROM `{$table}` ORDER BY `id` DESC LIMIT 1" );
	}

	/**
	 * Knowing the stored hash is not enough: it is not the token.
	 */
	public function test_sending_the_stored_hash_is_rejected(): void {
		$this->configure( 'real-token' );

		$response = ( new Webhook() )->handle(
			null,
			array(
				'verification_token' => hash( 'sha256', 'real-token' ),
				'email'              => 'hash-replay@example.com',
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A site with no token configured rejects every token, and a non-string
	 * token still cannot reach hash() and raise a TypeError.
	 *
	 * @dataProvider tokens_against_an_unconfigured_site
	 *
	 * @param mixed $token Token to send.
	 * @param int   $status Expected status.
	 */
	public function test_an_unconfigured_site_rejects_everything( $token, int $status ): void {
		$this->write_options_raw( array( 'default_role' => 'subscriber' ) );

		$response = ( new Webhook() )->handle(
			null,
			array(
				'verification_token' => $token,
				'email'              => 'unconfigured@example.com',
			)
		);

		$this->assertSame( $status, $response->get_status() );
	}

	/**
	 * Tokens an unconfigured site must refuse.
	 *
	 * @return array
	 */
	public function tokens_against_an_unconfigured_site(): array {
		return array(
			'a token'             => array( 'anything', 401 ),
			'hash of empty token' => array( hash( 'sha256', '' ), 401 ),
			'array'               => array( array( 'x' ), 401 ),
			'integer'             => array( 42, 401 ),
			'empty string'        => array( '', 400 ),
		);
	}

	/**
	 * The request log says why authentication failed, so a site owner can
	 * tell "no token saved" from "wrong token" without WP_DEBUG. The caller is
	 * told only "Unauthorized": the reason would describe the site to an
	 * attacker.
	 */
	public function test_a_wrong_token_is_logged_as_a_mismatch_but_answered_generically(): void {
		$this->configure( 'real-token' );

		$response = ( new Webhook() )->handle(
			null,
			array(
				'verification_token' => 'wrong-token',
				'email'              => 'mismatch@example.com',
			)
		);

		$this->assertSame( array( 'error' => 'Unauthorized' ), $response->get_data() );
		$this->assertSame( 'Unauthorized: token mismatch', $this->last_logged_error() );
	}

	/**
	 * The other reason: nothing saved at all.
	 */
	public function test_an_unconfigured_site_is_logged_as_such_but_answered_generically(): void {
		$this->write_options_raw( array( 'default_role' => 'subscriber' ) );

		$response = ( new Webhook() )->handle(
			null,
			array(
				'verification_token' => 'any-token',
				'email'              => 'unconfigured@example.com',
			)
		);

		$this->assertSame( array( 'error' => 'Unauthorized' ), $response->get_data() );
		$this->assertSame( 'Unauthorized: no token configured', $this->last_logged_error() );
	}

	/**
	 * The hash key is redacted from debug output like the token itself.
	 */
	public function test_the_token_hash_is_redacted_from_the_debug_log(): void {
		$hash     = hash( 'sha256', 'redact-me' );
		$log_file = tempnam( sys_get_temp_dir(), 'kofi-log-' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Redirecting error_log is the only way to capture logger output.
		$original = ini_set( 'error_log', $log_file );

		try {
			\MembersForKofi\Logging\DebugLogger::info( 'Context with a hash', array( 'options' => array( 'verification_token_sha256' => $hash ) ) );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring the original value.
			ini_set( 'error_log', false === $original ? '' : $original );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local temp file, not a URL.
		$contents = (string) file_get_contents( $log_file );
		wp_delete_file( $log_file );

		$this->assertStringContainsString( 'Context with a hash', $contents );
		$this->assertStringNotContainsString( $hash, $contents );
		$this->assertStringContainsString( '[REDACTED]', $contents );
	}

	// ------------------------------------------------------------------
	// FMEA fixes (1.3.0)
	// ------------------------------------------------------------------

	/**
	 * Sends a donation with the configured token.
	 *
	 * @param array $fields Payload fields.
	 * @return \WP_REST_Response
	 */
	private function pay( array $fields ): \WP_REST_Response {
		return ( new Webhook() )->handle( null, array_merge( array( 'verification_token' => 'tok' ), $fields ) );
	}

	/**
	 * Counts user-log rows with an action for an email.
	 *
	 * @param string $email  Email.
	 * @param string $action Action, or a LIKE pattern.
	 * @return int
	 */
	private function user_log_count( string $email, string $action ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, test-only read.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}members_for_kofi_user_logs` WHERE email = %s AND action LIKE %s", $email, $action ) );
	}

	/**
	 * A new account must not inherit the site's "New User Default Role".
	 *
	 * That was a bypass of every check on what a webhook may grant: with the
	 * site default set to administrator, a payment created an administrator.
	 */
	public function test_a_new_account_never_inherits_the_site_default_role(): void {
		$this->configure( 'tok', array( 'tier_role_map' => array( 'Gold' => 'author' ) ) );
		$previous = get_option( 'default_role' );
		update_option( 'default_role', 'administrator' );

		try {
			$this->assertSame(
				200,
				$this->pay(
					array(
						'email'     => 'inherit@example.com',
						'tier_name' => 'Gold',
					)
				)->get_status()
			);
			$this->assertSame( array( 'author' ), get_user_by( 'email', 'inherit@example.com' )->roles );

			// With no role to give at all, the account gets none -- not the site's.
			$this->configure( 'tok', array( 'default_role' => '' ) );
			$this->pay( array( 'email' => 'no-role@example.com' ) );
			$this->assertSame( array(), get_user_by( 'email', 'no-role@example.com' )->roles );
		} finally {
			update_option( 'default_role', $previous );
		}
	}

	/**
	 * A new account carries no trace of the email in its public fields.
	 */
	public function test_a_new_account_does_not_publish_the_email(): void {
		$this->configure( 'tok' );

		$this->pay(
			array(
				'email'     => 'private.person@example.com',
				'from_name' => 'Jo Public',
				'is_public' => true,
			)
		);
		$user = get_user_by( 'email', 'private.person@example.com' );

		$this->assertStringStartsWith( 'kofi-', $user->user_login );
		$this->assertSame( $user->user_login, $user->user_nicename );
		$this->assertStringNotContainsString( 'private', $user->user_nicename );
		$this->assertSame( 'Jo Public', $user->display_name );
		$this->assertSame( 'Jo Public', get_user_meta( $user->ID, 'nickname', true ) );
		$this->assertSame( '1', (string) get_user_meta( $user->ID, Webhook::CREATED_META, true ) );

		// Donors sign in with their email, which WordPress accepts as a login.
		wp_set_password( 'known-pass-123', $user->ID );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate_email_password( null, 'private.person@example.com', 'known-pass-123' ) );
	}

	/**
	 * A private supporter's Ko-fi name is not published, and a name that is
	 * itself an email address is never used.
	 */
	public function test_private_or_email_shaped_names_become_supporter(): void {
		$this->configure( 'tok' );

		$this->pay(
			array(
				'email'     => 'quiet@example.com',
				'from_name' => 'Hidden Name',
				'is_public' => false,
			)
		);
		$this->pay(
			array(
				'email'     => 'shaped@example.com',
				'from_name' => 'shaped@example.com',
				'is_public' => true,
			)
		);

		$this->assertSame( 'Supporter', get_user_by( 'email', 'quiet@example.com' )->display_name );
		$this->assertSame( 'Supporter', get_user_by( 'email', 'shaped@example.com' )->display_name );
	}

	/**
	 * A custom role with admin powers cannot be handed out, whatever it is
	 * called. Editor stays assignable: it is a legitimate mapping.
	 */
	public function test_a_role_with_admin_capabilities_is_never_assigned(): void {
		add_role(
			'kofi_test_boss',
			'Boss',
			array(
				'read'           => true,
				'manage_options' => true,
			)
		);

		try {
			$this->assertFalse( Webhook::is_assignable_role( 'kofi_test_boss' ) );
			$this->assertFalse( Webhook::is_assignable_role( 'administrator' ) );
			$this->assertTrue( Webhook::is_assignable_role( 'editor' ) );

			$this->configure( 'tok', array( 'tier_role_map' => array( 'Gold' => 'kofi_test_boss' ) ) );
			$this->pay(
				array(
					'email'     => 'boss@example.com',
					'tier_name' => 'Gold',
				)
			);
			$this->assertNotContains( 'kofi_test_boss', get_user_by( 'email', 'boss@example.com' )->roles );

			// A rejected save hands back what was stored, so store a clean state.
			$this->configure( 'tok' );
			$settings  = new AdminSettings();
			$sanitized = $settings->sanitize_options(
				array(
					'verification_token' => 'tok',
					'tier_role_map'      => array(
						'tier' => array( 'Gold' ),
						'role' => array( 'kofi_test_boss' ),
					),
					'default_role'       => 'kofi_test_boss',
					'enable_expiry'      => true,
					'role_expiry_days'   => 35,
				)
			);
			$this->assertArrayNotHasKey( 'Gold', (array) ( $sanitized['tier_role_map'] ?? array() ) );
			$this->assertNotSame( 'kofi_test_boss', $sanitized['default_role'] ?? '' );
		} finally {
			remove_role( 'kofi_test_boss' );
			$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
		}
	}

	/**
	 * Shop orders and commissions are purchases, not support: no account, no
	 * role, still a 200 so Ko-fi does not retry, and a line in the user log.
	 *
	 * @dataProvider non_granting_types
	 *
	 * @param string $type Ko-fi event type.
	 */
	public function test_purchases_do_not_grant_membership( string $type ): void {
		$this->configure( 'tok' );

		$response = $this->pay(
			array(
				'email'    => 'buyer@example.com',
				'type'     => $type,
				'shipping' => array( 'street_address' => '1 Main St' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( get_user_by( 'email', 'buyer@example.com' ) );
		$this->assertSame( 1, $this->user_log_count( 'buyer@example.com', 'Ignored: ' . $type ) );
	}

	/**
	 * Ko-fi event types that are purchases.
	 *
	 * @return array
	 */
	public function non_granting_types(): array {
		return array(
			'shop order' => array( 'Shop Order' ),
			'commission' => array( 'Commission' ),
		);
	}

	/**
	 * Donations, subscriptions and payloads without a type still grant, and a
	 * site can opt shop orders back in.
	 */
	public function test_support_still_grants_and_the_types_are_filterable(): void {
		$this->configure( 'tok' );

		foreach ( array( 'Donation', 'Subscription', '' ) as $i => $type ) {
			$this->pay(
				array(
					'email' => "support{$i}@example.com",
					'type'  => $type,
				)
			);
			$this->assertContains( 'subscriber', get_user_by( 'email', "support{$i}@example.com" )->roles, "Type '{$type}' must grant." );
		}

		$allow = static function ( array $types ): array {
			$types[] = 'Shop Order';
			return $types;
		};
		add_filter( 'members_for_kofi_granting_types', $allow );
		try {
			$this->pay(
				array(
					'email' => 'merch@example.com',
					'type'  => 'Shop Order',
				)
			);
		} finally {
			remove_filter( 'members_for_kofi_granting_types', $allow );
		}
		$this->assertContains( 'subscriber', get_user_by( 'email', 'merch@example.com' )->roles );
	}

	/**
	 * A shipping address never reaches the stored request payload.
	 */
	public function test_a_shipping_address_is_not_stored(): void {
		global $wpdb;

		( new RequestLogger() )->log_request(
			array(
				'email'    => 'ship@example.com',
				'shipping' => array( 'street_address' => '1 Main St' ),
			),
			200,
			true
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table, test-only read.
		$payload = (string) $wpdb->get_var( "SELECT payload FROM `{$wpdb->prefix}members_for_kofi_request_logs` ORDER BY id DESC LIMIT 1" );

		$this->assertStringNotContainsString( 'Main St', $payload );
		$this->assertStringContainsString( '[REDACTED]', $payload );
	}

	/**
	 * A redelivered Ko-fi message is processed once; a new message is not
	 * mistaken for it, nor is the same message id for another donor.
	 */
	public function test_a_redelivered_message_is_processed_once(): void {
		$this->configure( 'tok' );
		$payment = array(
			'email'      => 'once@example.com',
			'message_id' => 'msg-' . wp_generate_password( 8, false, false ),
			'amount'     => '5.00',
		);

		$this->assertSame( 200, $this->pay( $payment )->get_status() );
		$this->assertSame( 200, $this->pay( $payment )->get_status() );
		$this->assertSame( 1, $this->user_log_count( 'once@example.com', 'Donation received' ) );

		$this->pay( array( 'message_id' => 'msg-other-' . wp_generate_password( 8, false, false ) ) + $payment );
		$this->assertSame( 2, $this->user_log_count( 'once@example.com', 'Donation received' ) );

		$this->pay( array( 'email' => 'twice@example.com' ) + $payment );
		$this->assertSame( 1, $this->user_log_count( 'twice@example.com', 'Donation received' ) );
	}

	/**
	 * A tier name with no mapping is recorded where the site owner looks.
	 */
	public function test_an_unmapped_tier_is_logged(): void {
		$this->configure( 'tok', array( 'tier_role_map' => array( 'Gold' => 'author' ) ) );

		$this->pay(
			array(
				'email'     => 'renamed@example.com',
				'tier_name' => 'Gold Plus',
			)
		);
		$this->pay(
			array(
				'email'     => 'mapped@example.com',
				'tier_name' => 'gold',
			)
		);

		$this->assertSame( 1, $this->user_log_count( 'renamed@example.com', 'Unmapped tier: Gold Plus' ) );
		$this->assertSame( 0, $this->user_log_count( 'mapped@example.com', 'Unmapped tier%' ) );
	}

	/**
	 * The string "false" is not a subscription payment.
	 */
	public function test_a_string_false_is_not_a_subscription(): void {
		$this->configure( 'tok', array( 'only_subscriptions' => true ) );

		$this->pay(
			array(
				'email'                   => 'stringy@example.com',
				'is_subscription_payment' => 'false',
			)
		);

		$this->assertFalse( get_user_by( 'email', 'stringy@example.com' ) );
	}

	/**
	 * Failures are counted against the address the filter supplies, so a site
	 * behind a proxy can stop all clients sharing one bucket.
	 */
	public function test_failures_are_counted_per_filtered_client_address(): void {
		$this->configure( 'tok' );
		$client = static function (): string {
			return '203.0.113.9';
		};
		add_filter( 'members_for_kofi_client_ip', $client );

		try {
			( new Webhook() )->handle(
				null,
				array(
					'verification_token' => 'wrong',
					'email'              => 'x@example.com',
				)
			);
			$this->assertSame( 1, (int) get_transient( Webhook::failure_transient_key( '203.0.113.9' ) ) );
		} finally {
			remove_filter( 'members_for_kofi_client_ip', $client );
			Webhook::reset_failure_count( '203.0.113.9' );
		}
	}

	/**
	 * Ko-fi's test button is recorded but creates nothing; the token is still
	 * checked; and a real transaction id is processed as before.
	 */
	public function test_the_kofi_test_button_creates_no_account(): void {
		$this->configure( 'tok' );

		$test = $this->pay(
			array(
				'email'               => 'jo.example@example.com',
				'kofi_transaction_id' => Webhook::KOFI_TEST_TRANSACTION_ID,
			)
		);
		$this->assertSame( 200, $test->get_status() );
		$this->assertFalse( get_user_by( 'email', 'jo.example@example.com' ) );
		$this->assertSame( 1, $this->user_log_count( 'jo.example@example.com', 'Ko-fi test received' ) );

		$wrong = ( new Webhook() )->handle(
			null,
			array(
				'verification_token'  => 'wrong',
				'email'               => 'jo.example@example.com',
				'kofi_transaction_id' => Webhook::KOFI_TEST_TRANSACTION_ID,
			)
		);
		$this->assertSame( 401, $wrong->get_status() );

		$this->pay(
			array(
				'email'               => 'real.payer@example.com',
				'kofi_transaction_id' => '7c1e9a52-3b4d-4f86-a0c2-5d9e8b1f3a67',
			)
		);
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'real.payer@example.com' ) );
	}
}
