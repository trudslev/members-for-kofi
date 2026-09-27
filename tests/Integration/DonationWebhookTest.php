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
 * @subpackage Tests
 */

namespace MembersForKofi\Tests\Integration;

// These tests run on the host, outside WordPress, and must post a byte-exact
// form-encoded body and shell out to WP-CLI to read the site's state back.
// The WordPress HTTP/JSON helpers are not available or not faithful enough here.
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export

/**
 * Injects real Ko-fi donation requests into a running WordPress site.
 *
 * These drive the endpoint over HTTP exactly as Ko-fi does -- an
 * `application/x-www-form-urlencoded` body of `data=<json>` -- so the request
 * travels the full production path: rewrite rule, query var, `php://input`,
 * `parse_str()`, JSON decode, then user creation and role assignment.
 *
 * That path is the point. Calling `Webhook::handle( null, $array )` from a unit
 * test hands the handler a ready-made PHP array and skips body parsing
 * entirely, so a whole class of bug -- anything that mangles the raw body
 * before `json_decode()` -- is invisible to it.
 *
 * @group integration
 */
class DonationWebhookTest extends IntegrationTestCase {

	/**
	 * Posts a donation exactly the way Ko-fi does.
	 *
	 * @param array $payload Donation payload, before JSON encoding.
	 * @return array{status:int,body:string,json:mixed}
	 */
	private function post_donation( array $payload ): array {
		$curl = curl_init( $this->base_url . '/webhook-kofi' );

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				// Ko-fi form-encodes a single `data` field holding the JSON string.
				CURLOPT_POSTFIELDS     => 'data=' . rawurlencode( json_encode( $payload ) ),
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/x-www-form-urlencoded',
					'User-Agent: Ko-fi',
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);

		$body   = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$error  = curl_error( $curl );

		if ( '' !== $error ) {
			$this->fail( "cURL error talking to {$this->base_url}: {$error}" );
		}

		return array(
			'status' => $status,
			'body'   => (string) $body,
			'json'   => json_decode( (string) $body, true ),
		);
	}

	/**
	 * Reads a user's roles back out of the site via WP-CLI.
	 *
	 * @param string $email Email address of the user.
	 * @return array<string> Roles, empty when the user does not exist.
	 */
	private function roles_for( string $email ): array {
		$command = sprintf(
			'docker compose -f %s run --rm -T wpcli wp user get %s --field=roles 2>/dev/null',
			escapeshellarg( dirname( __DIR__, 2 ) . '/docker-compose.test.yml' ),
			escapeshellarg( $email )
		);

		$output = trim( (string) shell_exec( $command ) );

		if ( '' === $output ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $output ) ) ) );
	}

	/**
	 * Reads back the donor message the site actually recorded.
	 *
	 * Status codes alone are too weak here: mangling the raw body can leave
	 * still-valid JSON (`\\` collapsing to `\`, `\uXXXX` losing its escape), so
	 * the request succeeds while the stored content is quietly wrong. Comparing
	 * the round-tripped message catches that.
	 *
	 * @param string $email Donor address to look up.
	 * @return string|null Recorded message, or null when nothing was logged.
	 */
	private function recorded_message_for( string $email ): ?string {
		// `wp db query` shells out to a MariaDB client that cannot authenticate
		// against MySQL 8, so go through WordPress's own database layer instead.
		$php = sprintf(
			'global $wpdb; $p = $wpdb->get_var( $wpdb->prepare( '
			. '"SELECT payload FROM {$wpdb->prefix}members_for_kofi_request_logs WHERE email = %%s ORDER BY id DESC LIMIT 1", '
			. '%s ) ); if ( $p ) { echo $p; }',
			var_export( $email, true )
		);

		$command = sprintf(
			'docker compose -f %s run --rm -T wpcli wp eval %s 2>/dev/null',
			escapeshellarg( dirname( __DIR__, 2 ) . '/docker-compose.test.yml' ),
			escapeshellarg( $php )
		);

		$payload = trim( (string) shell_exec( $command ) );

		if ( '' === $payload ) {
			return null;
		}

		$decoded = json_decode( $payload, true );

		return is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : null;
	}

	/**
	 * Reads back the whole payload the site recorded for a donation.
	 *
	 * @param string $email Donor address to look up.
	 * @return array<string,mixed>|null Decoded payload, or null when nothing was logged.
	 */
	private function recorded_payload_for( string $email ): ?array {
		$php = sprintf(
			'global $wpdb; $p = $wpdb->get_var( $wpdb->prepare( '
			. '"SELECT payload FROM {$wpdb->prefix}members_for_kofi_request_logs WHERE email = %%s ORDER BY id DESC LIMIT 1", '
			. '%s ) ); if ( $p ) { echo $p; }',
			var_export( $email, true )
		);

		$command = sprintf(
			'docker compose -f %s run --rm -T wpcli wp eval %s 2>/dev/null',
			escapeshellarg( dirname( __DIR__, 2 ) . '/docker-compose.test.yml' ),
			escapeshellarg( $php )
		);

		$raw = trim( (string) shell_exec( $command ) );

		if ( '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * A plain donation is accepted and maps the tier onto a role.
	 */
	public function test_donation_assigns_mapped_tier_role(): void {
		$email = $this->donor_email( 'gold-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'amount'                  => '10.00',
				'currency'                => 'USD',
				'is_subscription_payment' => true,
				'message'                 => 'Keep up the good work',
			)
		);

		$this->assertSame( 200, $response['status'], 'Ko-fi donation should be accepted.' );
		$this->assertTrue( $response['json']['success'] ?? false );
		$this->assertContains( 'editor', $this->roles_for( $email ), 'Gold tier maps to the editor role.' );
	}

	/**
	 * An unmapped tier still creates the donor and grants the default role.
	 */
	public function test_donation_with_unmapped_tier_gets_default_role(): void {
		$email = $this->donor_email( 'unmapped-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'NoSuchTier',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 200, $response['status'] );
		$this->assertContains( 'subscriber', $this->roles_for( $email ) );
	}

	/**
	 * Donor messages containing double quotes must survive body parsing.
	 *
	 * JSON escapes an embedded quote as \", so anything that strips backslashes
	 * from the raw body before json_decode() turns a perfectly good donation
	 * into a 400 and silently drops it.
	 */
	public function test_donation_with_quotes_in_message_is_processed(): void {
		$email = $this->donor_email( 'quote-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
				'message'                 => 'She said "thank you" and meant it',
			)
		);

		$this->assertSame(
			200,
			$response['status'],
			'A donor message containing double quotes must not break payload parsing.'
		);
		$this->assertContains( 'editor', $this->roles_for( $email ) );
		$this->assertSame(
			'She said "thank you" and meant it',
			$this->recorded_message_for( $email ),
			'The donor message must round-trip unchanged.'
		);
	}

	/**
	 * Backslashes in a donor message must not corrupt the payload either.
	 */
	public function test_donation_with_backslashes_in_message_is_processed(): void {
		$email = $this->donor_email( 'backslash-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Silver',
				'is_subscription_payment' => true,
				'message'                 => 'Recipe saved to C:\\recipes\\bread.txt',
			)
		);

		$this->assertSame( 200, $response['status'], 'Backslashes must survive payload parsing.' );
		$this->assertContains( 'author', $this->roles_for( $email ) );
		$this->assertSame(
			'Recipe saved to C:\\recipes\\bread.txt',
			$this->recorded_message_for( $email ),
			'Backslashes must round-trip unchanged, not be silently collapsed.'
		);
	}

	/**
	 * Apostrophes are the single most common thing in a real donor message.
	 */
	public function test_donation_with_apostrophe_in_message_is_processed(): void {
		$email = $this->donor_email( 'apostrophe-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Bronze',
				'is_subscription_payment' => true,
				'message'                 => "It's the best sourdough I've baked",
			)
		);

		$this->assertSame( 200, $response['status'] );
		$this->assertContains( 'contributor', $this->roles_for( $email ) );
		$this->assertSame(
			"It's the best sourdough I've baked",
			$this->recorded_message_for( $email ),
			'The donor message must round-trip unchanged.'
		);
	}

	/**
	 * Unicode and emoji arrive as \uXXXX escapes and must decode cleanly.
	 */
	public function test_donation_with_unicode_message_is_processed(): void {
		$email = $this->donor_email( 'unicode-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
				'message'                 => 'Tak for brødet! 🥖 Håber det går godt',
			)
		);

		$this->assertSame( 200, $response['status'], 'Unicode escapes must survive payload parsing.' );
		$this->assertContains( 'editor', $this->roles_for( $email ) );
		$this->assertSame(
			'Tak for brødet! 🥖 Håber det går godt',
			$this->recorded_message_for( $email ),
			'Unicode must round-trip unchanged, not lose its escapes.'
		);
	}

	/**
	 * Newlines inside a message are escaped as \n and must not break parsing.
	 */
	public function test_donation_with_multiline_message_is_processed(): void {
		$email = $this->donor_email( 'multiline-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
				'message'                 => "First line\nSecond line\n\tIndented",
			)
		);

		$this->assertSame( 200, $response['status'] );
		$this->assertContains( 'editor', $this->roles_for( $email ) );
	}

	/**
	 * Changing tier must not leave the previous tier's role behind.
	 *
	 * A supporter who drops from Gold to Bronze should end up with only the
	 * Bronze role. Because the plugin tracks a single assigned role in user meta
	 * and overwrites it, the superseded role stops being tracked and expiry can
	 * never remove it -- so the donor silently keeps the higher privileges of a
	 * tier they no longer pay for.
	 */
	public function test_role_from_previous_tier_is_removed_on_downgrade(): void {
		$email = $this->donor_email( 'downgrade-donor' );

		$this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$this->assertContains( 'editor', $this->roles_for( $email ), 'Gold should grant the editor role.' );

		$this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Bronze',
				'is_subscription_payment' => true,
			)
		);

		$roles = $this->roles_for( $email );

		$this->assertContains( 'contributor', $roles, 'Bronze should grant the contributor role.' );
		$this->assertNotContains(
			'editor',
			$roles,
			'The superseded Gold role must be removed; otherwise the donor keeps privileges they no longer pay for.'
		);
	}

	/**
	 * Markup in a donor message must be stripped before it is stored.
	 *
	 * The webhook recursively sanitises the decoded payload, but that only runs
	 * on the real request path -- a unit test handing `handle()` a ready-made
	 * array skips it entirely, so this is the only place the rule can be proved.
	 *
	 * `message` is deliberately the field under test: `tier_name` is sanitised a
	 * second time by RequestLogger when it builds its own column, so asserting
	 * on that would pass even with the recursive sanitisation removed.
	 */
	public function test_donation_message_is_sanitised_before_storage(): void {
		$email = $this->donor_email( 'sanitise-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
				'message'                 => '<script>alert(1)</script>Thanks!',
			)
		);

		$this->assertSame( 200, $response['status'] );

		$recorded = $this->recorded_message_for( $email );

		$this->assertNotNull( $recorded, 'The donation should have been logged.' );
		$this->assertStringNotContainsString(
			'<script>',
			(string) $recorded,
			'Markup must be stripped from the donor message before storage.'
		);
		$this->assertStringContainsString( 'Thanks!', (string) $recorded, 'The text itself should survive.' );
	}

	/**
	 * The verification token must never be stored in the logged payload.
	 *
	 * The equivalent rule is already covered for the PHP error log; this covers
	 * the database side, where the whole payload is kept as JSON.
	 */
	public function test_verification_token_is_redacted_in_the_stored_payload(): void {
		$email = $this->donor_email( 'redaction-donor' );

		$this->post_donation(
			array(
				'verification_token'      => $this->token,
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$payload = $this->recorded_payload_for( $email );

		$this->assertNotNull( $payload, 'The donation should have been logged.' );
		$this->assertSame(
			'[REDACTED]',
			$payload['verification_token'] ?? null,
			'The stored payload must carry a redaction placeholder, not the token.'
		);
		$this->assertStringNotContainsString(
			$this->token,
			(string) wp_json_encode( $payload ),
			'The real verification token must not appear anywhere in the stored payload.'
		);
	}

	/**
	 * A wrong token is rejected and grants nothing.
	 */
	public function test_donation_with_invalid_token_is_rejected(): void {
		$email = $this->donor_email( 'forged-donor' );

		$response = $this->post_donation(
			array(
				'verification_token'      => 'not-the-right-token',
				'email'                   => $email,
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 401, $response['status'], 'A forged token must be rejected.' );
		$this->assertSame( array(), $this->roles_for( $email ), 'No user may be created for a rejected donation.' );
	}

	/**
	 * A token with stray whitespace is accepted over Ko-fi's real transport.
	 *
	 * The form-encoded body is run through sanitize_text_field(), which trims,
	 * and the token saved in settings is trimmed the same way. That holds for
	 * this path only: the REST route and callers handing handle() an array
	 * get the token exactly as sent, so this is deliberately an HTTP test.
	 */
	public function test_a_padded_token_is_accepted_over_http(): void {
		$response = $this->post_donation(
			array(
				'verification_token'      => "  {$this->token} \t",
				'email'                   => $this->donor_email( 'padded-token' ),
				'tier_name'               => 'Gold',
				'is_subscription_payment' => true,
			)
		);

		$this->assertSame( 200, $response['status'] );
	}

	/**
	 * Knowing the stored hash does not let anyone donate as the site's token.
	 */
	public function test_sending_the_stored_hash_is_rejected(): void {
		$response = $this->post_donation(
			array(
				'verification_token' => hash( 'sha256', $this->token ),
				'email'              => $this->donor_email( 'hash-replay' ),
			)
		);

		$this->assertSame( 401, $response['status'] );
		$this->assertSame( array( 'error' => 'Unauthorized' ), $response['json'], 'The caller must not learn why.' );
	}

	/**
	 * A plain GET on the endpoint is turned away and writes nothing.
	 *
	 * Ko-fi always POSTs. Before this, any crawler following the endpoint URL
	 * reached the request logger and inserted a row per visit -- an
	 * unauthenticated way to grow the table indefinitely.
	 */
	public function test_get_request_is_rejected_and_not_logged(): void {
		$before = $this->request_log_row_count();

		$curl = curl_init( $this->base_url . '/webhook-kofi' );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				// WordPress answers the slashless form with a canonical 301 to
				// /webhook-kofi/ before the handler ever runs, so follow it the
				// way a crawler would; the 405 is on the other side.
				CURLOPT_FOLLOWLOCATION => true,
			)
		);
		curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		unset( $curl );

		$this->assertSame( 405, $status, 'A GET on the webhook endpoint must be refused' );
		$this->assertSame(
			$before,
			$this->request_log_row_count(),
			'A GET must not write a request log row'
		);
	}

	/**
	 * Counts rows in the request log via WP-CLI.
	 *
	 * @return int
	 */
	private function request_log_row_count(): int {
		return (int) self::wp(
			'eval ' . escapeshellarg(
				'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}members_for_kofi_request_logs" );'
			)
		);
	}

	/**
	 * A verbatim Ko-fi subscription payload, captured from production.
	 *
	 * Taken from the request log after Ko-fi's own "first monthly" test button,
	 * so the field set, the types and the nulls are Ko-fi's rather than ours.
	 * Every other fixture in this suite is hand-written and tidier than
	 * reality: they all send `tier_name` as a non-empty string, where Ko-fi
	 * sends null, and none carry the Discord or transaction fields at all.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array
	 */
	private function real_kofi_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'verification_token'            => $this->token,
				'message_id'                    => 'dd25a0b5-0aaf-4096-b1ca-214399627547',
				'timestamp'                     => '2026-09-12T01:52:32Z',
				'type'                          => 'Subscription',
				'is_public'                     => true,
				'from_name'                     => 'Jo Example',
				'message'                       => 'Good luck with the integration!',
				'amount'                        => '3.00',
				'url'                           => 'https://ko-fi.com/Home/CoffeeShop?txid=00000000-1111-2222-3333-444444444444',
				'email'                         => 'replaced-by-caller@example.com',
				'currency'                      => 'USD',
				'is_subscription_payment'       => true,
				'is_first_subscription_payment' => true,
				'kofi_transaction_id'           => '00000000-1111-2222-3333-444444444444',
				'shop_items'                    => null,
				'tier_name'                     => null,
				'shipping'                      => null,
				'discord_username'              => 'Jo#4105',
				'discord_userid'                => '012345678901234567',
			),
			$overrides
		);
	}

	/**
	 * Reads the expiry timestamp the plugin stored for a donor.
	 *
	 * @param string $email Donor address.
	 * @return string
	 */
	private function assigned_at_for( string $email ): string {
		return self::wp(
			'eval ' . escapeshellarg(
				'$u = get_user_by( "email", ' . var_export( $email, true ) . ' );'
				. ' echo $u ? get_user_meta( $u->ID, "kofi_role_assigned_at", true ) : "";'
			)
		);
	}

	/**
	 * Ko-fi's real payload is accepted, nulls and all.
	 *
	 * `tier_name` arrives as null rather than a missing key or an empty string.
	 * The code survives that on `?? ''`, but nothing pinned it: passing the
	 * value straight into resolve_role_from_tier( string $tier ) would be a
	 * TypeError on every real donation while this suite stayed green.
	 */
	public function test_a_real_kofi_payload_is_accepted(): void {
		$email = $this->donor_email( 'real-payload' );

		$response = $this->post_donation( $this->real_kofi_payload( array( 'email' => $email ) ) );

		$this->assertSame( 200, $response['status'], 'Ko-fi\'s own payload must be accepted' );
		$this->assertTrue( (bool) ( $response['json']['success'] ?? false ) );
	}

	/**
	 * A null tier falls back to the default role rather than assigning nothing.
	 */
	public function test_a_real_kofi_payload_assigns_the_default_role(): void {
		$email = $this->donor_email( 'real-payload-role' );

		$this->post_donation( $this->real_kofi_payload( array( 'email' => $email ) ) );

		$this->assertContains(
			'subscriber',
			$this->roles_for( $email ),
			'A null tier_name should fall through to the configured default role'
		);
	}

	/**
	 * The donor message survives Ko-fi's real payload shape intact.
	 */
	public function test_a_real_kofi_payload_stores_the_message(): void {
		$email = $this->donor_email( 'real-payload-message' );

		$this->post_donation( $this->real_kofi_payload( array( 'email' => $email ) ) );

		$this->assertSame(
			'Good luck with the integration!',
			$this->recorded_message_for( $email )
		);
	}

	/**
	 * A renewal for an existing supporter keeps their role and refreshes expiry.
	 *
	 * Ko-fi's test buttons only ever send a *first* subscription payment, so
	 * this branch -- by far the most common one in real life, and the one every
	 * month after the first -- is never exercised against the live site.
	 */
	public function test_a_recurring_payment_keeps_the_role_and_refreshes_expiry(): void {
		$email = $this->donor_email( 'renewal' );

		$this->post_donation( $this->real_kofi_payload( array( 'email' => $email ) ) );

		$first = $this->assigned_at_for( $email );
		$this->assertNotSame( '', $first, 'Expected the first payment to record an expiry timestamp' );

		// The renewal Ko-fi sends a month later: same shape, not the first one.
		$response = $this->post_donation(
			$this->real_kofi_payload(
				array(
					'email'                         => $email,
					'is_first_subscription_payment' => false,
					'message_id'                    => 'a1b2c3d4-0000-1111-2222-333344445555',
					'message'                       => null,
				)
			)
		);

		$this->assertSame( 200, $response['status'], 'A renewal must be accepted' );
		$this->assertContains(
			'subscriber',
			$this->roles_for( $email ),
			'A renewal must not cost the supporter their role'
		);
		$this->assertNotSame(
			'',
			$this->assigned_at_for( $email ),
			'A renewal must refresh the expiry timestamp, or access lapses a month later'
		);
	}

	/**
	 * Ko-fi's real membership-tier payload resolves through the tier map.
	 *
	 * Captured from production after Ko-fi's "membership tier test" button.
	 * Two things make it worth keeping verbatim: tier_name is a genuine Ko-fi
	 * tier string rather than one we invented, and it arrives with
	 * is_first_subscription_payment = false -- a renewal, which is what every
	 * payment after the first one looks like and what the "first monthly"
	 * button never produces.
	 */
	public function test_a_real_membership_tier_payload_resolves_the_mapped_role(): void {
		$email = $this->donor_email( 'real-tier' );

		$response = $this->post_donation(
			$this->real_kofi_payload(
				array(
					'email'                         => $email,
					'tier_name'                     => 'Bronze',
					'amount'                        => '5.00',
					'is_first_subscription_payment' => false,
				)
			)
		);

		$this->assertSame( 200, $response['status'], 'A renewal carrying a tier must be accepted' );
		$this->assertContains(
			'contributor',
			$this->roles_for( $email ),
			'Bronze is mapped to contributor in the test environment'
		);
	}
}
