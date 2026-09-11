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
}
