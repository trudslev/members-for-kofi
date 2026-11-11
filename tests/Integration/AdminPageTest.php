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
// admin requests, and shell out to WP-CLI to read the site's state back.
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

/**
 * The admin page over real HTTP: who can see it, who can delete a log, and
 * what the filters return.
 *
 * Until 1.7.0 the log viewer ran on admin-ajax endpoints, which fire for any
 * logged-in user, including every subscriber this plugin creates. Those are
 * gone: the tabs are plain admin pages behind `manage_options`, and the one
 * write, deleting a log, is an admin-post form with a nonce and a capability
 * check. These tests drive WordPress's own dispatch and cookie
 * authentication, which no in-process call reproduces.
 *
 * @group integration
 */
class AdminPageTest extends IntegrationTestCase {

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
	 * Posts a form to admin-post.php without following the redirect.
	 *
	 * @param array       $fields POST fields, including `action`.
	 * @param string|null $jar    Cookie jar, or null for an anonymous request.
	 * @return array{status:int,location:string,body:string}
	 */
	private function admin_post( array $fields, ?string $jar = null ): array {
		$curl = curl_init( $this->base_url . '/wp-admin/admin-post.php' );
		$opts = array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( $fields ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_TIMEOUT        => 30,
		);

		if ( null !== $jar ) {
			$opts[ CURLOPT_COOKIEFILE ] = $jar;
		}

		curl_setopt_array( $curl, $opts );
		$raw    = (string) curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$size   = (int) curl_getinfo( $curl, CURLINFO_HEADER_SIZE );

		$location = '';
		if ( preg_match( '/^Location:\s*(\S+)/mi', substr( $raw, 0, $size ), $match ) ) {
			$location = $match[1];
		}

		return array(
			'status'   => $status,
			'location' => $location,
			'body'     => substr( $raw, $size ),
		);
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
	 * Counts rows in one of the log tables.
	 *
	 * @param string $table `request` or `user`.
	 * @return int
	 */
	private function log_count( string $table ): int {
		$php = sprintf( 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}members_for_kofi_%s_logs" );', $table );

		return (int) self::wp( 'eval ' . escapeshellarg( $php ) );
	}

	/**
	 * Seeds a row into each log, keyed to a unique address.
	 *
	 * @param string $marker  Label to make the rows findable.
	 * @param bool   $success Whether the request row was accepted.
	 * @return string The email used.
	 */
	private function seed_both_logs( string $marker, bool $success = true ): string {
		$email = $this->donor_email( $marker );

		$php = 'global $wpdb;'
			. ' $wpdb->insert( $wpdb->prefix . "members_for_kofi_user_logs", array('
			. ' "user_id" => 0, "email" => ' . var_export( $email, true ) . ','
			. ' "action" => "Donation received", "role" => "subscriber",'
			. ' "timestamp" => current_time( "mysql" ) ) );'
			. ' $wpdb->insert( $wpdb->prefix . "members_for_kofi_request_logs", array('
			. ' "email" => ' . var_export( $email, true ) . ', "tier_name" => "Gold",'
			. ' "payload" => "{}", "status_code" => ' . ( $success ? 200 : 401 ) . ', "success" => ' . ( $success ? 1 : 0 ) . ','
			. ' "error" => ' . var_export( $success ? '' : 'Unauthorized: token mismatch', true ) . ','
			. ' "timestamp" => current_time( "mysql" ) ) );'
			. ' echo "ok";';

		self::wp( 'eval ' . escapeshellarg( $php ) );

		return $email;
	}

	/**
	 * The delete nonce an administrator's Logs tab carries.
	 *
	 * @param string $jar Administrator session.
	 * @return string
	 */
	private function delete_nonce_from_page( string $jar ): string {
		$page = $this->get( $this->base_url . '/wp-admin/admin.php?page=members-for-kofi&tab=logs', $jar );

		$this->assertSame( 200, $page['status'] );
		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="([a-f0-9]{10})"/', $page['body'], 'The Logs tab should carry the delete form.' );
		preg_match( '/name="_wpnonce" value="([a-f0-9]{10})"/', $page['body'], $match );

		return $match[1];
	}

	/**
	 * Without a session, the delete handler is never reached.
	 */
	public function test_logged_out_caller_cannot_delete_a_log(): void {
		$this->seed_both_logs( 'page-anon' );
		$before = $this->log_count( 'request' );

		$response = $this->admin_post(
			array(
				'action'   => 'members_for_kofi_delete_log',
				'log'      => 'requests',
				'_wpnonce' => 'forged',
			)
		);

		$this->assertNotSame( 200, $response['status'] );
		$this->assertSame( $before, $this->log_count( 'request' ), 'An anonymous caller must not empty the log.' );
	}

	/**
	 * A subscriber cannot open the page, so never sees the delete nonce.
	 */
	public function test_subscriber_cannot_reach_the_admin_page(): void {
		$jar = $this->login( self::SUBSCRIBER_LOGIN, self::SUBSCRIBER_PASS );

		foreach ( array( '', '&tab=members', '&tab=logs' ) as $tab ) {
			$page = $this->get( $this->base_url . '/wp-admin/admin.php?page=members-for-kofi' . $tab, $jar );

			$this->assertNotSame( 200, $page['status'] );
			$this->assertStringNotContainsString( 'members_for_kofi_delete_log', $page['body'] );
			$this->assertStringNotContainsString( '@example.com', $page['body'], 'No donor address may reach a subscriber.' );
		}
	}

	/**
	 * A subscriber with a forged nonce is refused.
	 */
	public function test_subscriber_with_a_forged_nonce_cannot_delete(): void {
		$this->seed_both_logs( 'page-forged' );
		$before = $this->log_count( 'request' );
		$jar    = $this->login( self::SUBSCRIBER_LOGIN, self::SUBSCRIBER_PASS );

		$response = $this->admin_post(
			array(
				'action'   => 'members_for_kofi_delete_log',
				'log'      => 'requests',
				'_wpnonce' => 'forged0000',
			),
			$jar
		);

		$this->assertSame( 403, $response['status'] );
		$this->assertSame( $before, $this->log_count( 'request' ) );
	}

	/**
	 * The capability check refuses a subscriber even with a valid nonce.
	 */
	public function test_subscriber_with_a_valid_nonce_is_refused_by_capability(): void {
		$this->seed_both_logs( 'page-capability' );
		$before = $this->log_count( 'request' );
		$jar    = $this->login( self::SUBSCRIBER_LOGIN, self::SUBSCRIBER_PASS );
		$nonce  = $this->valid_nonce_for( $jar, self::SUBSCRIBER_LOGIN, 'members_for_kofi_delete_log' );

		$response = $this->admin_post(
			array(
				'action'   => 'members_for_kofi_delete_log',
				'log'      => 'requests',
				'_wpnonce' => $nonce,
			),
			$jar
		);

		$this->assertSame( 403, $response['status'] );
		$this->assertSame( $before, $this->log_count( 'request' ), 'A subscriber holding a valid nonce must still be refused.' );
	}

	/**
	 * Even an administrator is refused without a genuine nonce, so another
	 * site cannot make their browser empty the log.
	 */
	public function test_administrator_with_a_forged_nonce_cannot_delete(): void {
		$this->seed_both_logs( 'page-admin-forged' );
		$before = $this->log_count( 'request' );
		$jar    = $this->login( 'admin', 'admin' );

		$response = $this->admin_post(
			array(
				'action'   => 'members_for_kofi_delete_log',
				'log'      => 'requests',
				'_wpnonce' => 'forged0000',
			),
			$jar
		);

		$this->assertSame( 403, $response['status'] );
		$this->assertSame( $before, $this->log_count( 'request' ), 'A forged nonce must not empty the log.' );
	}

	/**
	 * An administrator deletes one log, and only that one.
	 */
	public function test_administrator_deletes_only_the_chosen_log(): void {
		$this->seed_both_logs( 'page-delete' );
		$this->assertGreaterThan( 0, $this->log_count( 'request' ) );
		$activity = $this->log_count( 'user' );

		$jar      = $this->login( 'admin', 'admin' );
		$response = $this->admin_post(
			array(
				'action'   => 'members_for_kofi_delete_log',
				'log'      => 'requests',
				'_wpnonce' => $this->delete_nonce_from_page( $jar ),
			),
			$jar
		);

		$this->assertSame( 302, $response['status'] );
		$this->assertStringContainsString( 'tab=logs', $response['location'] );
		$this->assertStringContainsString( 'deleted=1', $response['location'] );
		$this->assertSame( 0, $this->log_count( 'request' ), 'The request log must be empty.' );
		$this->assertSame( $activity, $this->log_count( 'user' ), 'Member activity must be kept.' );
	}

	/**
	 * The old AJAX endpoints no longer exist, for anyone.
	 */
	public function test_the_old_ajax_endpoints_are_gone(): void {
		$this->seed_both_logs( 'page-ajax' );
		$before = $this->log_count( 'request' );
		$jar    = $this->login( 'admin', 'admin' );

		$curl = curl_init( $this->base_url . '/wp-admin/admin-ajax.php' );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => http_build_query(
					array(
						'action'   => 'members_for_kofi_clear_logs',
						'log_type' => 'request',
					)
				),
				CURLOPT_COOKIEFILE     => $jar,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		$body = (string) curl_exec( $curl );

		$this->assertSame( '0', trim( $body ), 'WordPress answers "0" for an action nothing handles.' );
		$this->assertSame( $before, $this->log_count( 'request' ) );
	}

	/**
	 * Searching, the outcome filter and the log switch return the right rows.
	 */
	public function test_the_filters_return_the_right_rows(): void {
		$hit     = $this->seed_both_logs( 'page-filter-hit' );
		$miss    = $this->seed_both_logs( 'page-filter-miss' );
		$refused = $this->seed_both_logs( 'page-filter-refused', false );
		$jar     = $this->login( 'admin', 'admin' );
		$base    = $this->base_url . '/wp-admin/admin.php?page=members-for-kofi&tab=logs';

		$search = $this->get( $base . '&log=requests&s=' . rawurlencode( $hit ), $jar )['body'];
		$this->assertStringContainsString( $hit, $search );
		$this->assertStringNotContainsString( $miss, $search, 'Search must narrow the log, not return it whole.' );

		$only_refused = $this->get( $base . '&log=requests&outcome=refused', $jar )['body'];
		$this->assertStringContainsString( 'Refused: the token doesn&#039;t match', $only_refused );
		$this->assertStringNotContainsString( 'mfk-badge-success', $only_refused, 'The outcome filter must drop accepted requests.' );

		$activity = $this->get( $base . '&log=activity&s=' . rawurlencode( $hit ), $jar )['body'];
		$this->assertStringContainsString( 'Donation received', $activity, 'The activity log shows activity rows.' );
		$this->assertStringContainsString( 'Search email, action or role', $activity );
		unset( $refused );
	}

	/**
	 * The page size limits the rows, and paging moves through them.
	 */
	public function test_page_size_and_paging(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->seed_both_logs( 'page-paging' );
		}
		$jar  = $this->login( 'admin', 'admin' );
		$base = $this->base_url . '/wp-admin/admin.php?page=members-for-kofi&tab=logs&log=activity&per_page=10';

		$first  = $this->get( $base, $jar )['body'];
		$second = $this->get( $base . '&paged=2', $jar )['body'];

		$this->assertSame( 10, substr_count( $first, 'Donation received' ) );
		$this->assertStringContainsString( 'class="total-pages"', $first );
		$this->assertNotSame( $first, $second );
		$this->assertMatchesRegularExpression( '/name="paged" value="2"/', $second );
	}

	/**
	 * Each tab opens for an administrator.
	 */
	public function test_each_tab_opens_for_an_administrator(): void {
		$jar = $this->login( 'admin', 'admin' );

		$settings = $this->get( $this->base_url . '/wp-admin/admin.php?page=members-for-kofi', $jar );
		$this->assertSame( 200, $settings['status'] );
		$this->assertStringContainsString( 'Ko-fi connection', $settings['body'] );

		$members = $this->get( $this->base_url . '/wp-admin/admin.php?page=members-for-kofi&tab=members', $jar );
		$this->assertSame( 200, $members['status'] );
		$this->assertStringContainsString( 'Member since', $members['body'] );
	}
}
