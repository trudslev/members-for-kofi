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

// Runs on the host, outside WordPress: it must drive real logins and real
// admin-ajax requests, and shell out to WP-CLI to read the site's state back.
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

/**
 * Authorisation tests for the admin log viewer's AJAX endpoints.
 *
 * These matter more than a typical untested admin screen. The plugin's whole
 * purpose is creating logged-in low-privilege users from donations, and
 * `wp_ajax_` hooks fire for *any* authenticated user -- so every subscriber the
 * plugin creates can reach these endpoints. The nonce and capability checks are
 * the only things between them and other donors' email addresses.
 *
 * Driving this over real HTTP (rather than calling the handlers in-process) is
 * the point: it exercises WordPress's own admin-ajax dispatch, cookie
 * authentication and the `wp_ajax_` vs `wp_ajax_nopriv_` distinction, none of
 * which an in-process call reproduces.
 *
 * @group integration
 */
class AdminAjaxTest extends IntegrationTestCase {

	private const SUBSCRIBER_LOGIN = 'kofi_ajax_subscriber';
	private const SUBSCRIBER_PASS  = 'Subscriber_pass_123';

	/**
	 * Directory for this run's cookie jars.
	 *
	 * @var string
	 */
	private string $jar_dir;

	/**
	 * Creates the low-privilege user the authorisation tests need.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::wp( 'user delete ' . self::SUBSCRIBER_LOGIN . ' --yes' );
		self::wp(
			sprintf(
				'user create %s %s@example.com --role=subscriber --user_pass=%s',
				self::SUBSCRIBER_LOGIN,
				self::SUBSCRIBER_LOGIN,
				self::SUBSCRIBER_PASS
			)
		);
	}

	/**
	 * Removes the low-privilege user again.
	 */
	public static function tearDownAfterClass(): void {
		self::wp( 'user delete ' . self::SUBSCRIBER_LOGIN . ' --yes' );

		parent::tearDownAfterClass();
	}

	/**
	 * Resolves the environment under test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->jar_dir = sys_get_temp_dir();
	}

	/**
	 * Logs in over HTTP and returns the path to the resulting cookie jar.
	 *
	 * @param string $login    Username.
	 * @param string $password Password.
	 * @return string Cookie jar path.
	 */
	private function login( string $login, string $password ): string {
		$jar = tempnam( $this->jar_dir, 'kofi-jar-' );

		$curl = curl_init( $this->base_url . '/wp-login.php' );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => http_build_query(
					array(
						'log'         => $login,
						'pwd'         => $password,
						'wp-submit'   => 'Log In',
						'redirect_to' => $this->base_url . '/wp-admin/',
					)
				),
				CURLOPT_COOKIEJAR      => $jar,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		curl_exec( $curl );
		$error = curl_error( $curl );

		if ( '' !== $error ) {
			$this->fail( "cURL error logging in as {$login}: {$error}" );
		}

		// cURL only writes the cookie jar when the handle is destroyed.
		unset( $curl );

		$this->assertStringContainsString(
			'wordpress_logged_in',
			(string) file_get_contents( $jar ),
			"Expected to be logged in as {$login}."
		);

		return $jar;
	}

	/**
	 * Performs a GET request, optionally authenticated.
	 *
	 * @param string      $url URL to fetch.
	 * @param string|null $jar Cookie jar, or null for an anonymous request.
	 * @return array{status:int,body:string}
	 */
	private function get( string $url, ?string $jar = null ): array {
		$curl = curl_init( $url );
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
		);

		if ( null !== $jar ) {
			$opts[ CURLOPT_COOKIEFILE ] = $jar;
		}

		curl_setopt_array( $curl, $opts );
		$body   = (string) curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );

		return array(
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * Posts to admin-ajax.php.
	 *
	 * @param array       $fields POST fields, including `action`.
	 * @param string|null $jar    Cookie jar, or null for an anonymous request.
	 * @return array{status:int,body:string,json:mixed}
	 */
	private function ajax( array $fields, ?string $jar = null ): array {
		$curl = curl_init( $this->base_url . '/wp-admin/admin-ajax.php' );
		$opts = array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( $fields ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
		);

		if ( null !== $jar ) {
			$opts[ CURLOPT_COOKIEFILE ] = $jar;
		}

		curl_setopt_array( $curl, $opts );
		$body   = (string) curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );

		return array(
			'status' => $status,
			'body'   => $body,
			'json'   => json_decode( $body, true ),
		);
	}

	/**
	 * Reads the AJAX nonces the plugin hands its own admin JavaScript.
	 *
	 * Scraped from the settings page rather than generated, because nonces are
	 * bound to the user and session -- this is exactly how the real UI gets them.
	 *
	 * @param string $jar Cookie jar for an administrator session.
	 * @return array<string,string>
	 */
	private function admin_nonces( string $jar ): array {
		$page = $this->get( $this->base_url . '/wp-admin/admin.php?page=members-for-kofi', $jar );

		$this->assertSame( 200, $page['status'], 'Administrator should be able to load the settings page.' );

		if ( ! preg_match( '/var kofiMembers = (\{.*?\});/s', $page['body'], $matches ) ) {
			$this->fail( 'Could not find the localised kofiMembers nonces on the settings page.' );
		}

		$decoded = json_decode( $matches[1], true );
		$this->assertIsArray( $decoded, 'kofiMembers should be valid JSON.' );

		return $decoded;
	}

	/**
	 * Mints a genuinely valid nonce for an already-logged-in user.
	 *
	 * Nonces are bound to the user *and* their session token, so one generated
	 * in a bare CLI context will not verify against an HTTP request. This reads
	 * the session token out of the login cookie and generates the nonce in that
	 * same session, producing one the site will accept.
	 *
	 * That is what makes it possible to test the capability check at all: with a
	 * valid nonce the request gets past check_ajax_referer() and actually reaches
	 * current_user_can(), which is otherwise unreachable from a low-privilege
	 * caller and would go forever unverified.
	 *
	 * @param string $jar    Cookie jar for the user's session.
	 * @param string $login  The user's login name.
	 * @param string $action Nonce action.
	 * @return string
	 */
	private function valid_nonce_for( string $jar, string $login, string $action ): string {
		$cookie = '';

		foreach ( explode( "\n", (string) file_get_contents( $jar ) ) as $line ) {
			if ( false === strpos( $line, 'wordpress_logged_in' ) ) {
				continue;
			}

			$fields = explode( "\t", $line );
			if ( isset( $fields[6] ) ) {
				$cookie = rawurldecode( trim( $fields[6] ) );
				break;
			}
		}

		$this->assertNotSame( '', $cookie, "Could not read the logged-in cookie for {$login}." );

		$php = sprintf(
			'$_COOKIE[LOGGED_IN_COOKIE] = %s; $u = get_user_by( "login", %s ); wp_set_current_user( $u->ID ); echo wp_create_nonce( %s );',
			var_export( $cookie, true ),
			var_export( $login, true ),
			var_export( $action, true )
		);

		$nonce = self::wp( 'eval ' . escapeshellarg( $php ) );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{10}$/', $nonce, 'Expected a well-formed nonce.' );

		return $nonce;
	}

	/**
	 * Counts rows in the request log table.
	 *
	 * @return int
	 */
	private function request_log_count(): int {
		$php = 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}members_for_kofi_request_logs" );';

		return (int) self::wp( 'eval ' . escapeshellarg( $php ) );
	}

	/**
	 * Writes a row into the request log by making a real donation.
	 */
	private function seed_request_log(): void {
		$curl = curl_init( $this->base_url . '/webhook-kofi' );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => 'data=' . rawurlencode(
					(string) wp_json_encode(
						array(
							'verification_token' => 'test-verification-token',
							'email'              => $this->donor_email( 'ajax-seed' ),
							'tier_name'          => 'Gold',
						)
					)
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		curl_exec( $curl );
	}

	/**
	 * An anonymous caller cannot reach the handlers at all.
	 *
	 * The plugin registers only `wp_ajax_` hooks, never `wp_ajax_nopriv_`, so
	 * WordPress itself refuses before any plugin code runs.
	 */
	public function test_logged_out_caller_is_refused(): void {
		foreach ( array( 'members_for_kofi_clear_logs', 'members_for_kofi_pagination' ) as $action ) {
			$response = $this->ajax(
				array(
					'action'   => $action,
					'log_type' => 'user',
				)
			);

			$this->assertSame( 400, $response['status'], "Anonymous {$action} should be refused." );
			$this->assertSame( '0', trim( $response['body'] ), 'WordPress should refuse an unregistered nopriv action.' );
		}
	}

	/**
	 * A logged-in subscriber without a nonce is refused.
	 */
	public function test_subscriber_without_nonce_is_refused(): void {
		$jar = $this->login( self::SUBSCRIBER_LOGIN, self::SUBSCRIBER_PASS );

		foreach ( array( 'members_for_kofi_clear_logs', 'members_for_kofi_pagination' ) as $action ) {
			$response = $this->ajax(
				array(
					'action'   => $action,
					'log_type' => 'user',
				),
				$jar
			);

			$this->assertSame( 403, $response['status'], "Subscriber {$action} should be refused." );
			$this->assertSame( '-1', trim( $response['body'] ), 'Nonce verification should reject the request.' );
		}
	}

	/**
	 * A subscriber cannot load the settings page, so cannot obtain the nonces.
	 *
	 * This is what keeps the nonce check from being trivially bypassable by the
	 * very users this plugin creates.
	 */
	public function test_subscriber_cannot_reach_the_settings_page(): void {
		$jar  = $this->login( self::SUBSCRIBER_LOGIN, self::SUBSCRIBER_PASS );
		$page = $this->get( $this->base_url . '/wp-admin/admin.php?page=members-for-kofi', $jar );

		$this->assertNotSame( 200, $page['status'], 'A subscriber must not be able to load the settings page.' );
		$this->assertStringNotContainsString(
			'clearLogsNonce',
			$page['body'],
			'The settings page must never leak AJAX nonces to a low-privilege user.'
		);
	}

	/**
	 * A subscriber cannot destroy the logs.
	 *
	 * Asserts the effect, not just the status: the rows must still be there.
	 */
	public function test_subscriber_cannot_clear_logs(): void {
		$this->seed_request_log();
		$before = $this->request_log_count();
		$this->assertGreaterThan( 0, $before, 'Expected a seeded request log row.' );

		$jar = $this->login( self::SUBSCRIBER_LOGIN, self::SUBSCRIBER_PASS );
		$this->ajax(
			array(
				'action'      => 'members_for_kofi_clear_logs',
				'log_type'    => 'request',
				'_ajax_nonce' => 'forged-nonce',
			),
			$jar
		);

		$this->assertSame( $before, $this->request_log_count(), 'A subscriber must not be able to clear the logs.' );
	}

	/**
	 * The capability check refuses a subscriber even with a valid nonce.
	 *
	 * The nonce check runs first, so in normal operation `current_user_can()`
	 * never gets reached by a low-privilege caller and its correctness is never
	 * demonstrated. This test hands the subscriber a valid nonce specifically so
	 * that the second layer is the one doing the refusing.
	 */
	public function test_subscriber_with_valid_nonce_is_refused_by_capability_check(): void {
		$this->seed_request_log();
		$before = $this->request_log_count();
		$this->assertGreaterThan( 0, $before, 'Expected a seeded request log row.' );

		$jar   = $this->login( self::SUBSCRIBER_LOGIN, self::SUBSCRIBER_PASS );
		$nonce = $this->valid_nonce_for( $jar, self::SUBSCRIBER_LOGIN, 'members_for_kofi_clear_logs' );

		$response = $this->ajax(
			array(
				'action'      => 'members_for_kofi_clear_logs',
				'log_type'    => 'request',
				'_ajax_nonce' => $nonce,
			),
			$jar
		);

		$this->assertFalse(
			$response['json']['success'] ?? true,
			'A subscriber holding a valid nonce must still be refused.'
		);
		$this->assertStringContainsString(
			'permission',
			(string) ( $response['json']['data'] ?? '' ),
			'The refusal should come from the capability check.'
		);
		$this->assertSame( $before, $this->request_log_count(), 'The logs must be untouched.' );
	}

	/**
	 * An administrator with a genuine nonce can read the logs.
	 */
	public function test_administrator_can_paginate_logs(): void {
		$jar    = $this->login( 'admin', 'admin' );
		$nonces = $this->admin_nonces( $jar );

		$response = $this->ajax(
			array(
				'action'      => 'members_for_kofi_pagination',
				'log_type'    => 'request',
				'paged'       => 1,
				'_ajax_nonce' => $nonces['paginationNonce'],
			),
			$jar
		);

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['json']['success'] ?? false, 'Administrator pagination should succeed.' );
	}

	/**
	 * An administrator with a genuine nonce can clear the logs, and rows go away.
	 */
	public function test_administrator_can_clear_logs(): void {
		$this->seed_request_log();
		$this->assertGreaterThan( 0, $this->request_log_count(), 'Expected a seeded request log row.' );

		$jar    = $this->login( 'admin', 'admin' );
		$nonces = $this->admin_nonces( $jar );

		$response = $this->ajax(
			array(
				'action'      => 'members_for_kofi_clear_logs',
				'log_type'    => 'request',
				'_ajax_nonce' => $nonces['clearLogsNonce'],
			),
			$jar
		);

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['json']['success'] ?? false, 'Administrator should be able to clear logs.' );
		$this->assertSame( 0, $this->request_log_count(), 'Clearing logs must actually empty the table.' );
	}

	// ------------------------------------------------------------------
	// What the log viewer actually returns, not merely that it responds
	// ------------------------------------------------------------------

	/**
	 * Seeds one row into each log table, keyed to a unique address.
	 *
	 * @param string $marker Label to make the rows findable.
	 * @return string The email used.
	 */
	private function seed_both_logs( string $marker ): string {
		$email = $this->donor_email( $marker );

		$php = 'global $wpdb;'
			. ' $wpdb->insert( $wpdb->prefix . "members_for_kofi_user_logs", array('
			. ' "user_id" => 0, "email" => ' . var_export( $email, true ) . ','
			. ' "action" => "Donation received", "role" => "subscriber",'
			. ' "timestamp" => current_time( "mysql" ) ) );'
			. ' $wpdb->insert( $wpdb->prefix . "members_for_kofi_request_logs", array('
			. ' "email" => ' . var_export( $email, true ) . ', "tier_name" => "Gold",'
			. ' "payload" => "{}", "status_code" => 200, "success" => 1,'
			. ' "timestamp" => current_time( "mysql" ) ) );'
			. ' echo "ok";';

		self::wp( 'eval ' . escapeshellarg( $php ) );

		return $email;
	}

	/**
	 * The HTML returned by the log viewer, for one AJAX action.
	 *
	 * @param array  $fields Request fields.
	 * @param string $jar    Cookie jar.
	 * @return string
	 */
	private function rendered_table( array $fields, string $jar ): string {
		$response = $this->ajax( $fields, $jar );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['json']['success'] ?? false, 'Expected the action to succeed.' );

		return (string) ( $response['json']['data'] ?? '' );
	}

	/**
	 * Searching narrows the result to the matching row.
	 *
	 * Asserting only on a 200 would pass just as well if search were ignored
	 * and the full table came back every time.
	 */
	public function test_search_actually_filters_the_log(): void {
		$wanted   = $this->seed_both_logs( 'ajax-search-hit' );
		$unwanted = $this->seed_both_logs( 'ajax-search-miss' );

		$jar    = $this->login( 'admin', 'admin' );
		$nonces = $this->admin_nonces( $jar );

		$html = $this->rendered_table(
			array(
				'action'        => 'members_for_kofi_filter_logs',
				'search'        => $wanted,
				'paged'         => 1,
				'log_type'      => 'user',
				'rows_per_page' => 25,
				'_ajax_nonce'   => $nonces['filterNonce'],
			),
			$jar
		);

		$this->assertStringContainsString( $wanted, $html, 'The matching row should be shown' );
		$this->assertStringNotContainsString( $unwanted, $html, 'A non-matching row must be filtered out' );
	}

	/**
	 * Searching from the Request tab returns request rows, not user rows.
	 *
	 * The handler ignored log_type entirely and always rendered the user log,
	 * so a search performed on the Request tab answered with the wrong
	 * table. Every other log action honoured log_type, which is what made it
	 * hard to notice.
	 */
	public function test_search_respects_the_selected_log_type(): void {
		$email = $this->seed_both_logs( 'ajax-search-type' );

		$jar    = $this->login( 'admin', 'admin' );
		$nonces = $this->admin_nonces( $jar );

		$html = $this->rendered_table(
			array(
				'action'        => 'members_for_kofi_filter_logs',
				'search'        => $email,
				'paged'         => 1,
				'log_type'      => 'request',
				'rows_per_page' => 25,
				'_ajax_nonce'   => $nonces['filterNonce'],
			),
			$jar
		);

		// "Tier Name" is a request-log column; "User ID" is a user-log column.
		$this->assertStringContainsString( 'Tier Name', $html, 'Expected the request log table' );
		$this->assertStringNotContainsString( 'User ID', $html, 'Got the user log table instead' );
	}

	/**
	 * Switching log type returns the other table.
	 */
	public function test_switching_log_type_returns_the_other_table(): void {
		$this->seed_both_logs( 'ajax-switch' );

		$jar    = $this->login( 'admin', 'admin' );
		$nonces = $this->admin_nonces( $jar );

		$request_html = $this->rendered_table(
			array(
				'action'      => 'members_for_kofi_switch_log_type',
				'log_type'    => 'request',
				'_ajax_nonce' => $nonces['switchLogTypeNonce'],
			),
			$jar
		);

		$user_html = $this->rendered_table(
			array(
				'action'      => 'members_for_kofi_switch_log_type',
				'log_type'    => 'user',
				'_ajax_nonce' => $nonces['switchLogTypeNonce'],
			),
			$jar
		);

		$this->assertStringContainsString( 'Tier Name', $request_html );
		$this->assertStringNotContainsString( 'User ID', $request_html );

		$this->assertStringContainsString( 'User ID', $user_html );
		$this->assertStringNotContainsString( 'Tier Name', $user_html );
	}

	/**
	 * The page size asked for is the page size returned.
	 */
	public function test_rows_per_page_changes_how_many_rows_come_back(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->seed_both_logs( "ajax-rows-{$i}" );
		}

		$jar    = $this->login( 'admin', 'admin' );
		$nonces = $this->admin_nonces( $jar );

		$ten = $this->rendered_table(
			array(
				'action'        => 'members_for_kofi_update_rows_per_page',
				'log_type'      => 'user',
				'rows_per_page' => 10,
				'_ajax_nonce'   => $nonces['rowsPerPageNonce'],
			),
			$jar
		);

		$twenty_five = $this->rendered_table(
			array(
				'action'        => 'members_for_kofi_update_rows_per_page',
				'log_type'      => 'user',
				'rows_per_page' => 25,
				'_ajax_nonce'   => $nonces['rowsPerPageNonce'],
			),
			$jar
		);

		$this->assertSame( 10, substr_count( $ten, '<tr' ) - 1, 'Expected ten data rows plus the header row' );
		$this->assertGreaterThan(
			substr_count( $ten, '<tr' ),
			substr_count( $twenty_five, '<tr' ),
			'Asking for 25 rows should return more rows than asking for 10'
		);
	}

	/**
	 * Page two is not page one.
	 *
	 * The existing pagination test asserts only that the request succeeds, which
	 * it would even if every page returned identical rows.
	 */
	public function test_pagination_returns_a_different_page(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->seed_both_logs( "ajax-page-{$i}" );
		}

		$jar    = $this->login( 'admin', 'admin' );
		$nonces = $this->admin_nonces( $jar );

		$page_one = $this->rendered_table(
			array(
				'action'      => 'members_for_kofi_pagination',
				'log_type'    => 'user',
				'paged'       => 1,
				'_ajax_nonce' => $nonces['paginationNonce'],
			),
			$jar
		);

		$page_two = $this->rendered_table(
			array(
				'action'      => 'members_for_kofi_pagination',
				'log_type'    => 'user',
				'paged'       => 2,
				'_ajax_nonce' => $nonces['paginationNonce'],
			),
			$jar
		);

		$this->assertNotSame( $page_one, $page_two, 'Page two must not repeat page one' );
	}

	/**
	 * Refreshing shows a row written after the page was first rendered.
	 */
	public function test_refresh_returns_current_data(): void {
		$jar    = $this->login( 'admin', 'admin' );
		$nonces = $this->admin_nonces( $jar );

		$before = $this->rendered_table(
			array(
				'action'      => 'members_for_kofi_refresh_logs',
				'log_type'    => 'user',
				'_ajax_nonce' => $nonces['refreshNonce'],
			),
			$jar
		);

		$email = $this->seed_both_logs( 'ajax-refresh' );

		$after = $this->rendered_table(
			array(
				'action'      => 'members_for_kofi_refresh_logs',
				'log_type'    => 'user',
				'_ajax_nonce' => $nonces['refreshNonce'],
			),
			$jar
		);

		$this->assertStringNotContainsString( $email, $before );
		$this->assertStringContainsString( $email, $after, 'A refresh must pick up rows written since the last render' );
	}
}
