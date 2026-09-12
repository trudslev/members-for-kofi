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
		update_option(
			'members_for_kofi_options',
			array_merge(
				array(
					'verification_token' => $token,
					'only_subscriptions' => false,
					'default_role'       => 'subscriber',
					'tier_role_map'      => array(),
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
}
