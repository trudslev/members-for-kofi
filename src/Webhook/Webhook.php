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

namespace MembersForKofi\Webhook;

defined( 'ABSPATH' ) || exit;

use WP_User;
use MembersForKofi\Logging\DebugLogger;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Logging\RequestLogger;

use get_option;

/**
 * Handles incoming webhook requests for the Members for Ko-fi plugin.
 *
 * This class is responsible for processing webhook payloads, verifying
 * tokens, creating users, and assigning roles based on the received data.
 *
 * @package MembersForKofi
 */
class Webhook {

	/**
	 * Roles that cannot be assigned via webhook for security reasons.
	 *
	 * The 'administrator' role is explicitly forbidden to prevent privilege escalation attacks.
	 * This is a critical security boundary: if webhooks could assign admin roles, an attacker
	 * who compromises the Ko-fi webhook endpoint could gain full WordPress admin access.
	 *
	 * This constant is used by AdminSettings to filter role selection dropdowns, ensuring
	 * administrators cannot accidentally configure mappings that would create security vulnerabilities.
	 *
	 * @since 1.0.0
	 * @var array<string> Array of role slugs that are not allowed via webhook assignment.
	 *
	 * @see AdminSettings::render_tier_role_map_field() Where this constant filters available roles.
	 * @see AdminSettings::render_default_role_field() Where this constant filters default role options.
	 */
	public const DISALLOWED_ROLES = array( 'administrator' );

	/**
	 * Capabilities a webhook-assigned role must never carry.
	 *
	 * Blocking the `administrator` slug alone left any custom role with admin
	 * powers assignable by anyone who can pay. These are the capabilities that
	 * amount to running the site. `unfiltered_html` is deliberately absent:
	 * editors carry it on single sites, and mapping a tier to editor is a
	 * legitimate configuration.
	 *
	 * @var array<string>
	 */
	public const DANGEROUS_CAPABILITIES = array(
		'manage_options',
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'install_plugins',
		'activate_plugins',
		'edit_plugins',
		'delete_plugins',
		'update_plugins',
		'install_themes',
		'edit_themes',
		'switch_themes',
		'edit_files',
		'update_core',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_options',
	);

	/**
	 * Ko-fi event types that grant membership.
	 *
	 * Shop orders and commissions are purchases, not support: they used to
	 * create an account and hand out the default role. Filterable through
	 * `members_for_kofi_granting_types` for a site that sells access as a
	 * shop item. A payload without a type is treated as granting, which keeps
	 * older and hand-made payloads working.
	 *
	 * @var array<string>
	 */
	public const GRANTING_TYPES = array( 'Donation', 'Subscription' );

	/**
	 * User meta naming a role the user already had when the webhook first
	 * assigned it. Such a role was granted by someone else, so expiry and tier
	 * changes stop tracking it but never remove it.
	 *
	 * @var string
	 */
	public const PREEXISTING_ROLE_META = 'kofi_role_preexisting';

	/**
	 * User meta marking an account this plugin created.
	 *
	 * @var string
	 */
	public const CREATED_META = 'kofi_created_by_plugin';

	/**
	 * The transaction id Ko-fi puts in every "Send test" webhook.
	 *
	 * Real payments carry a random one. A test used to be processed like a
	 * payment and leave a "Jo Example" account holding a membership role.
	 *
	 * @var string
	 */
	public const KOFI_TEST_TRANSACTION_ID = '00000000-1111-2222-3333-444444444444';

	/**
	 * The donor address in every Ko-fi "Send test" webhook.
	 *
	 * Used only to find accounts that tests created before 1.3.0.
	 *
	 * @var string
	 */
	public const KOFI_TEST_EMAIL = 'jo.example@example.com';

	/**
	 * How long a delivered Ko-fi message is remembered, to ignore redelivery.
	 *
	 * @var int
	 */
	public const DUPLICATE_WINDOW = 7 * DAY_IN_SECONDS;

	/**
	 * Failed attempts from one IP before the endpoint starts shedding them.
	 *
	 * Only failures count, and an authenticated request is never throttled,
	 * so a real donation cannot be rejected no matter what else is going on.
	 *
	 * @var int
	 */
	public const FAILURE_LIMIT = 30;

	/**
	 * How long failures are remembered, in seconds.
	 *
	 * @var int
	 */
	public const FAILURE_WINDOW = 300;

	/**
	 * Why the last authentication failure happened, for the request log only.
	 *
	 * Kept out of the response: telling an unauthenticated caller whether a
	 * token is configured at all would describe the site to an attacker.
	 *
	 * @var string
	 */
	private string $auth_failure_detail = '';

	/**
	 * Constructor for the Webhook class.
	 */
	public function __construct() {}

	/**
	 * Handles incoming webhook requests.
	 *
	 * This function processes the webhook payload, validates it, and triggers the appropriate actions.
	 *
	 * @param \WP_REST_Request|null $request The REST API request object, or null if not provided.
	 * @param array|null            $data    The webhook payload data, or null if not provided.
	 *
	 * @return \WP_REST_Response The response object indicating success or failure.
	 */
	public function handle( ?\WP_REST_Request $request = null, ?array $data = null ): \WP_REST_Response {
		// Initialize request logger and payload data.
		$request_logger = new RequestLogger();
		$payload_data   = array();

		// Only a real HTTP hit on the endpoint has a method to check; a caller
		// handing us $data, or the REST route, has already been vouched for.
		$from_http_body = ( null === $data && ! $request instanceof \WP_REST_Request );

		// Ko-fi always POSTs. A crawler following the URL, or someone opening it
		// in a browser, used to reach the logger and write a row -- which is how
		// an unauthenticated GET could grow the table unboundedly.
		if ( $from_http_body && ! $this->is_post_request() ) {
			return new \WP_REST_Response( array( 'error' => 'Method not allowed' ), 405 );
		}

		if ( null === $data ) {
			if ( $request instanceof \WP_REST_Request ) {
				$data = $request->get_json_params();
			} else {
				parse_str( file_get_contents( 'php://input' ), $payload );
				if ( ! is_array( $payload ) || ! array_key_exists( 'data', $payload ) ) {
					return $this->reject( $request_logger, 400, 'Invalid payload' );
				}
				// No wp_unslash() here: WordPress only adds slashes to the
				// superglobals, never to php://input. Stripping them would eat the
				// escapes in the JSON itself -- \" breaks the parse outright, while
				// \\ and \uXXXX decode to silently corrupted text.
				$raw_json = wp_check_invalid_utf8( $payload['data'] );
				// Basic trim to avoid leading/trailing junk.
				$raw_json = trim( $raw_json );
				$data     = json_decode( $raw_json, true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
					return $this->reject( $request_logger, 400, 'Malformed JSON payload' );
				}
				// Recursively sanitize text fields (shallow sanitize for scalar values).
				array_walk_recursive(
					$data,
					static function ( &$value ) {
						if ( is_string( $value ) ) {
							$value = sanitize_text_field( $value );
						}
					}
				);
			}
		}

		if ( ! is_array( $data ) ) {
			return $this->reject( $request_logger, 400, 'Invalid payload' );
		}

		// Store payload for logging.
		$payload_data = $data;

		// A request that has not authenticated must not be able to put content
		// of its choosing into the database: the payload column is TEXT, so the
		// old code let anyone push ~64 KB per request into the log table for as
		// long as they liked. Failures are recorded without their payload, and
		// shed entirely once the same IP has produced too many.
		if ( ! $this->is_authenticated( $data ) ) {
			$rejection = $this->process( $data );

			return $this->reject(
				$request_logger,
				$rejection->get_status(),
				$rejection->get_data()['error'] ?? 'Unauthorized',
				$this->auth_failure_detail
			);
		}

		// Process the request and log the result.
		$response = $this->process( $data );

		// Log the request with the response status.
		$status_code = $response->get_status();
		$success     = $status_code >= 200 && $status_code < 300;
		$error       = $success ? '' : ( $response->get_data()['error'] ?? 'Unknown error' );

		$request_logger->log_request( $payload_data, $status_code, $success, $error );

		return $response;
	}

	/**
	 * Reports whether the request carries the configured verification token.
	 *
	 * Only a hash of the token is stored, so the incoming token is hashed and
	 * the two hashes compared. Constant-time by design: hash_equals() compares
	 * every byte, where !== short-circuits at the first difference.
	 * The is_string() guard is not cosmetic -- hash() and hash_equals() raise a
	 * TypeError on anything else, and casting an array warns, so a payload
	 * sending a non-string token would otherwise break a public endpoint.
	 *
	 * @param array $body The decoded webhook payload.
	 * @return bool
	 */
	private function is_authenticated( array $body ): bool {
		if ( empty( $body['verification_token'] ) || ! is_string( $body['verification_token'] ) ) {
			return false;
		}

		$expected = VerificationToken::expected_hash( get_option( VerificationToken::OPTION ) );

		if ( '' === $expected ) {
			return false;
		}

		return hash_equals( $expected, VerificationToken::hash( $body['verification_token'] ) );
	}

	/**
	 * Reports whether the current HTTP request used POST.
	 *
	 * @return bool
	 */
	private function is_post_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the HTTP method, not form input.
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		return 'POST' === $method;
	}

	/**
	 * Records and answers a request that did not authenticate.
	 *
	 * The payload is deliberately not passed to the logger: this request is
	 * unauthenticated, so nothing it sent should be persisted. Once an IP is
	 * over the failure limit nothing is written at all and the request is shed
	 * with a 429.
	 *
	 * @param RequestLogger $logger     Logger to record the attempt with.
	 * @param int           $status     HTTP status the request earned.
	 * @param string        $error      Error message to return and log.
	 * @param string        $log_detail More specific message for the log only; the caller still gets $error.
	 * @return \WP_REST_Response
	 */
	private function reject( RequestLogger $logger, int $status, string $error, string $log_detail = '' ): \WP_REST_Response {
		if ( $this->too_many_recent_failures() ) {
			return new \WP_REST_Response( array( 'error' => 'Too many requests' ), 429 );
		}

		$this->record_failure();

		$logger->log_request( array(), $status, false, '' !== $log_detail ? $log_detail : $error );

		return new \WP_REST_Response( array( 'error' => $error ), $status );
	}

	/**
	 * Transient key holding the recent failure count for an address.
	 *
	 * @param string $ip Client address.
	 * @return string
	 */
	public static function failure_transient_key( string $ip ): string {
		return 'members_for_kofi_wh_fail_' . md5( $ip );
	}

	/**
	 * Clears the recorded failures for an address.
	 *
	 * @param string $ip Client address. Defaults to the current caller.
	 * @return void
	 */
	public static function reset_failure_count( string $ip = '' ): void {
		delete_transient( self::failure_transient_key( $ip ) );
	}

	/**
	 * The address the current request came from.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the connecting address, not form input.
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filters the address failures are counted against.
		 *
		 * Behind a reverse proxy REMOTE_ADDR is the proxy, so every client shares
		 * one failure bucket and anyone can exhaust it. A site that knows its
		 * proxy can return the real client address here. The default stays
		 * REMOTE_ADDR because forwarding headers are client-controlled unless a
		 * trusted proxy set them.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'members_for_kofi_client_ip', $ip );
	}

	/**
	 * The number of failures tolerated before shedding requests.
	 *
	 * Filterable so a site behind a proxy that collapses client addresses, or
	 * one that wants the endpoint wide open, can tune it. Zero disables it.
	 *
	 * @return int
	 */
	private function failure_limit(): int {
		return (int) apply_filters( 'members_for_kofi_webhook_failure_limit', self::FAILURE_LIMIT );
	}

	/**
	 * Reports whether this address has failed too often recently.
	 *
	 * @return bool
	 */
	private function too_many_recent_failures(): bool {
		$limit = $this->failure_limit();

		if ( $limit <= 0 ) {
			return false;
		}

		return (int) get_transient( self::failure_transient_key( $this->client_ip() ) ) >= $limit;
	}

	/**
	 * Counts one failure against the calling address.
	 *
	 * @return void
	 */
	private function record_failure(): void {
		$key = self::failure_transient_key( $this->client_ip() );

		set_transient( $key, (int) get_transient( $key ) + 1, self::FAILURE_WINDOW );
	}

	/**
	 * Processes the webhook payload and performs actions based on the data.
	 *
	 * @param array $body The webhook payload data.
	 * @return \WP_REST_Response The response object indicating success or failure.
	 */
	private function process( array $body ): \WP_REST_Response {
		$options = get_option( 'members_for_kofi_options' );
		$options = is_array( $options ) ? $options : array();

		// Only what is needed to follow a request: the payload carries the
		// donor's email, name and message, which must not reach a log file.
		DebugLogger::info(
			'Webhook received',
			array(
				'type'            => isset( $body['type'] ) && is_string( $body['type'] ) ? $body['type'] : '',
				'tier'            => isset( $body['tier_name'] ) && is_string( $body['tier_name'] ) ? $body['tier_name'] : '',
				'is_subscription' => $this->is_true( $body['is_subscription_payment'] ?? false ),
			)
		);

		if ( empty( $body['verification_token'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Missing verification token' ), 400 );
		}

		if ( ! $this->is_authenticated( $body ) ) {
			// Once the token field is write-only, this is the only diagnostic a
			// site owner has, so say which of the two it was -- in the request
			// log, which is readable without WP_DEBUG, as well as the debug log.
			if ( 'not_configured' === VerificationToken::failure_reason( get_option( VerificationToken::OPTION ) ) ) {
				DebugLogger::warning( 'No verification token configured' );
				$this->auth_failure_detail = 'Unauthorized: no token configured';
			} else {
				DebugLogger::warning( 'Verification token does not match' );
				$this->auth_failure_detail = 'Unauthorized: token mismatch';
			}
			return new \WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
		}

		// Ko-fi's "Send test" button. It has authenticated, which is what the
		// test is for -- the token and URL work -- so record that and stop: no
		// account, no role. Checked after authentication, so a test with the
		// wrong token is still refused and still says so.
		if ( isset( $body['kofi_transaction_id'] ) && self::KOFI_TEST_TRANSACTION_ID === $body['kofi_transaction_id'] ) {
			DebugLogger::info( 'Ko-fi test webhook received' );
			$test_email = isset( $body['email'] ) && is_string( $body['email'] ) && is_email( $body['email'] ) ? sanitize_email( $body['email'] ) : '';
			( new UserLogger() )->log_action( 0, $test_email, 'Ko-fi test received' );

			return new \WP_REST_Response(
				array(
					'success' => true,
					'test'    => true,
				),
				200
			);
		}

		if ( empty( $body['email'] ) || ! is_string( $body['email'] ) || ! is_email( $body['email'] ) ) {
			DebugLogger::warning( 'Invalid or missing email' );
			return new \WP_REST_Response( array( 'error' => 'Invalid email' ), 400 );
		}

		$email     = sanitize_email( $body['email'] );
		$tier_name = isset( $body['tier_name'] ) && is_string( $body['tier_name'] ) ? sanitize_text_field( $body['tier_name'] ) : '';
		$amount    = floatval( $body['amount'] ?? 0 );
		$currency  = isset( $body['currency'] ) && is_string( $body['currency'] ) ? sanitize_text_field( $body['currency'] ) : 'USD';
		$type      = isset( $body['type'] ) && is_string( $body['type'] ) ? sanitize_text_field( $body['type'] ) : '';

		$user_logger = new UserLogger();

		// Every outcome below answers 200: the payment was received and
		// understood, and anything else makes Ko-fi retry a request that would
		// be decided the same way again.
		if ( ! $this->grants_membership( $type ) ) {
			$user = get_user_by( 'email', $email );
			$user_logger->log_action( $user ? $user->ID : 0, $email, $this->log_label( 'Ignored', $type ) );
			return new \WP_REST_Response( array( 'success' => true ), 200 );
		}

		if ( ! empty( $options['only_subscriptions'] ) && ! $this->is_true( $body['is_subscription_payment'] ?? false ) ) {
			DebugLogger::info( 'Ignoring non-subscription payment due to only_subscriptions setting' );
			$user = get_user_by( 'email', $email );
			$user_logger->log_action( $user ? $user->ID : 0, $email, 'Ignored non-subscription payment' );
			return new \WP_REST_Response( array( 'success' => true ), 200 );
		}

		// Two deliveries for the same new email used to race: WordPress checks
		// that an email is unused in PHP, with no unique key behind it, so
		// simultaneous requests could each create an account -- the same login
		// and address several times over. One request per email at a time.
		$lock = $this->lock_email( $email );

		try {
			$delivery_key = $this->delivery_key( $body, $email );
			if ( '' !== $delivery_key && get_transient( $delivery_key ) ) {
				DebugLogger::info( 'Ignoring a redelivered Ko-fi message' );
				return new \WP_REST_Response( array( 'success' => true ), 200 );
			}

			// Read inside the lock: a request that waited may find the account
			// the one before it just created.
			$user = get_user_by( 'email', $email );

			if ( ! $user ) {
				$user_id = $this->create_user( $email, $this->display_name_for( $body ) );
				if ( is_wp_error( $user_id ) ) {
					DebugLogger::error( 'User creation failed', array( 'error' => $user_id->get_error_message() ) );
					return new \WP_REST_Response( array( 'error' => 'User creation failed' ), 500 );
				}
				update_user_meta( $user_id, self::CREATED_META, 1 );
				$user = get_user_by( 'ID', $user_id );
				DebugLogger::info( 'New user created', array( 'user_id' => $user_id ) );

				$user_logger->log_action( $user_id, $email, 'User created' );
			}

			$role = $this->resolve_role_from_tier( $tier_name, $options );

			if ( '' !== $tier_name && ! $this->tier_is_mapped( $tier_name, $options ) ) {
				// A tier renamed on Ko-fi stops matching its mapping without a
				// word; record it where the site owner looks.
				$user_logger->log_action( $user->ID, $email, $this->log_label( 'Unmapped tier', $tier_name ), $role );
			}

			if ( $role ) {
				$this->assign_role( $user, $role, $email, $user_logger );
			} else {
				DebugLogger::info( 'No matching tier or default role for user', array( 'tier' => $tier_name ) );
			}

			$user_logger->log_donation( $user->ID, $email, $amount, $currency );
			DebugLogger::info( 'Donation logged', array( 'user_id' => $user->ID ) );

			if ( '' !== $delivery_key ) {
				set_transient( $delivery_key, 1, self::DUPLICATE_WINDOW );
			}
		} finally {
			$this->unlock_email( $lock );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Gives the user the role a payment earned, and records what was done.
	 *
	 * Only one Ko-fi role is tracked per user. The previous one is dropped
	 * first, or a tier change would leave it attached forever: untracked, so
	 * expiry could never remove it. A role the user already held from someone
	 * else is tracked but marked, so neither a tier change nor expiry ever
	 * takes it away.
	 *
	 * @param \WP_User   $user        User to update.
	 * @param string     $role        Role to assign.
	 * @param string     $email       Email for the log.
	 * @param UserLogger $user_logger Logger.
	 * @return void
	 */
	private function assign_role( \WP_User $user, string $role, string $email, UserLogger $user_logger ): void {
		$previous_role = (string) get_user_meta( $user->ID, 'kofi_donation_assigned_role', true );
		$preexisting   = (string) get_user_meta( $user->ID, self::PREEXISTING_ROLE_META, true );

		if ( '' !== $previous_role && $previous_role !== $role
			&& in_array( $previous_role, $user->roles, true )
			&& $preexisting !== $previous_role ) {
			$user->remove_role( $previous_role );
			$user_logger->log_role_removal( $user->ID, $email, $previous_role );
		}

		if ( $previous_role !== $role ) {
			if ( in_array( $role, $user->roles, true ) ) {
				update_user_meta( $user->ID, self::PREEXISTING_ROLE_META, $role );
			} else {
				delete_user_meta( $user->ID, self::PREEXISTING_ROLE_META );
			}
		}

		$user->add_role( $role );
		update_user_meta( $user->ID, 'kofi_donation_assigned_role', $role );
		update_user_meta( $user->ID, 'kofi_role_assigned_at', time() );
		DebugLogger::info(
			'Assigned role to user',
			array(
				'user_id' => $user->ID,
				'role'    => $role,
			)
		);

		$user_logger->log_role_assignment( $user->ID, $email, $role );
	}

	/**
	 * Reports whether a Ko-fi event type grants membership.
	 *
	 * @param string $type Ko-fi's `type` field.
	 * @return bool
	 */
	private function grants_membership( string $type ): bool {
		if ( '' === $type ) {
			return true;
		}

		/**
		 * Filters the Ko-fi event types that grant membership.
		 *
		 * @param array<string> $types Defaults to Donation and Subscription.
		 */
		$types = (array) apply_filters( 'members_for_kofi_granting_types', self::GRANTING_TYPES );

		foreach ( $types as $granting ) {
			if ( is_string( $granting ) && 0 === strcasecmp( $granting, $type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads a Ko-fi boolean, which is a JSON boolean but must not be fooled by
	 * the string "false".
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function is_true( $value ): bool {
		return true === $value || 1 === $value || ( is_string( $value ) && in_array( strtolower( $value ), array( 'true', '1' ), true ) );
	}

	/**
	 * Builds a user-log action that fits its 50-character column.
	 *
	 * @param string $prefix Action.
	 * @param string $detail Detail.
	 * @return string
	 */
	private function log_label( string $prefix, string $detail ): string {
		$label = '' === $detail ? $prefix : $prefix . ': ' . $detail;

		return function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 50 ) : substr( $label, 0, 50 );
	}

	/**
	 * Transient key identifying one Ko-fi message for one donor.
	 *
	 * Keyed on `message_id`, which a redelivery repeats and a new payment does
	 * not. Not on `kofi_transaction_id`: Ko-fi's test button always sends the
	 * same placeholder one, so every test after the first would be dropped.
	 *
	 * @param array  $body  Payload.
	 * @param string $email Donor email.
	 * @return string Empty when the payload carries no message id.
	 */
	private function delivery_key( array $body, string $email ): string {
		$message_id = $body['message_id'] ?? '';

		if ( ! is_string( $message_id ) || '' === $message_id ) {
			return '';
		}

		return 'members_for_kofi_seen_' . md5( strtolower( $email ) . '|' . $message_id );
	}

	/**
	 * Takes the per-email database lock.
	 *
	 * Waits up to ten seconds, then carries on without it: a slow lock must
	 * never cost a payment.
	 *
	 * @param string $email Donor email.
	 * @return string Lock name, or '' when no lock is held.
	 */
	private function lock_email( string $email ): string {
		global $wpdb;

		// Lock names are global to the MySQL server, which other sites may
		// share, so the name carries this site's database and table prefix.
		$name = 'mfk_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . strtolower( $email ) );

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock has no WordPress API, and must never be cached.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, 10 ) );
		$wpdb->suppress_errors( $suppress );

		if ( '1' !== (string) $got ) {
			DebugLogger::warning( 'Could not lock the donor email; continuing without it' );
			return '';
		}

		return $name;
	}

	/**
	 * Releases a lock taken by lock_email().
	 *
	 * @param string $name Lock name, or ''.
	 * @return void
	 */
	private function unlock_email( string $name ): void {
		global $wpdb;

		if ( '' === $name ) {
			return;
		}

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock_email().
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * The public name for a new account.
	 *
	 * The donor's Ko-fi name when they chose to support publicly, otherwise a
	 * neutral label. Never the email address: that used to become the display
	 * name, which themes print on the author archive.
	 *
	 * @param array $body Payload.
	 * @return string
	 */
	private function display_name_for( array $body ): string {
		$name = isset( $body['from_name'] ) && is_string( $body['from_name'] ) ? sanitize_text_field( $body['from_name'] ) : '';

		if ( '' !== $name && $this->is_true( $body['is_public'] ?? false ) && ! is_email( $name ) ) {
			return $name;
		}

		return __( 'Supporter', 'members-for-kofi' );
	}

	/**
	 * Creates a new WordPress user with the given email.
	 *
	 * The login and URL slug are random rather than derived from the email,
	 * which used to put the address in public author URLs; donors sign in with
	 * their email, which WordPress accepts in place of the login. The role is
	 * explicitly empty: left unset, WordPress applies the site's "New User
	 * Default Role", which bypassed every check on what a webhook may grant --
	 * with that set to administrator, a payment created an administrator.
	 *
	 * @param string $email        The email address of the user to create.
	 * @param string $display_name Public name for the account.
	 * @return int|\WP_Error The user ID on success, or a WP_Error object on failure.
	 */
	protected function create_user( $email, string $display_name = '' ) {
		$login = self::generate_login();
		$name  = '' !== $display_name ? $display_name : __( 'Supporter', 'members-for-kofi' );

		return wp_insert_user(
			array(
				'user_login'    => $login,
				'user_nicename' => $login,
				'user_email'    => $email,
				'user_pass'     => wp_generate_password( 24 ),
				'display_name'  => $name,
				'nickname'      => $name,
				'role'          => '',
			)
		);
	}

	/**
	 * A random, unused login for a new supporter account.
	 *
	 * @return string
	 */
	public static function generate_login(): string {
		do {
			$login = 'kofi-' . strtolower( wp_generate_password( 10, false, false ) );
		} while ( username_exists( $login ) || get_user_by( 'slug', $login ) );

		return $login;
	}

	/**
	 * Reports whether a tier name has a mapping.
	 *
	 * @param string $tier    Tier name from Ko-fi.
	 * @param array  $options Plugin options.
	 * @return bool
	 */
	private function tier_is_mapped( string $tier, array $options ): bool {
		$map = $options['tier_role_map'] ?? array();

		if ( ! is_array( $map ) ) {
			return false;
		}

		$names = ( isset( $map['tier'] ) && is_array( $map['tier'] ) ) ? $map['tier'] : array_keys( $map );

		foreach ( $names as $name ) {
			if ( 0 === strcasecmp( (string) $name, $tier ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves the role from the given tier name based on the options.
	 *
	 * @param string $tier    The tier name to resolve the role for.
	 * @param array  $options The options containing tier-role mappings and default role.
	 * @return string|null The resolved role or null if no role is found.
	 */
	private function resolve_role_from_tier( string $tier, array $options ): ?string {
		$map          = $options['tier_role_map'] ?? array();
		$default_role = $options['default_role'] ?? '';

		if ( empty( $tier ) ) {
			return $this->validate_role( ! empty( $default_role ) ? $default_role : null );
		}

		// Support parallel array format (legacy) or associative map (current sanitizer output).
		if ( isset( $map['tier'], $map['role'] ) && is_array( $map['tier'] ) && is_array( $map['role'] ) ) {
			foreach ( $map['tier'] as $index => $tier_name ) {
				if ( strcasecmp( (string) $tier_name, $tier ) === 0 ) {
					if ( isset( $map['role'][ $index ] ) && ! empty( $map['role'][ $index ] ) ) {
						return $this->validate_role( $map['role'][ $index ] );
					}
					return $this->validate_role( ! empty( $default_role ) ? $default_role : null );
				}
			}
		} else {
			foreach ( $map as $tier_name => $role ) {
				if ( strcasecmp( (string) $tier_name, $tier ) === 0 ) {
					if ( ! empty( $role ) ) {
						return $this->validate_role( $role );
					}
					return $this->validate_role( ! empty( $default_role ) ? $default_role : null );
				}
			}
		}

		return $this->validate_role( ! empty( $default_role ) ? $default_role : null );
	}

	/**
	 * Validates that a role is allowed to be assigned via webhook.
	 *
	 * This is a critical security function that prevents disallowed roles
	 * (such as administrator) from being assigned through webhooks, even if
	 * they were somehow configured in the settings.
	 *
	 * @param string|null $role The role to validate.
	 * @return string|null The role if valid, null if disallowed.
	 */
	private function validate_role( ?string $role ): ?string {
		if ( empty( $role ) ) {
			return null;
		}

		// Security: Block disallowed roles, by name and by what they can do.
		if ( in_array( $role, self::DISALLOWED_ROLES, true ) || self::is_too_powerful( $role ) ) {
			DebugLogger::error(
				'Security: Blocked attempt to assign disallowed role via webhook',
				array( 'role' => $role )
			);
			return null;
		}

		// A mapping can outlive the role it points at, through a typo or a role
		// deleted later. add_role() would write the unknown slug into the user's
		// capability meta: harmless today, but it starts granting capabilities
		// the moment anything creates a role with that slug.
		if ( ! wp_roles()->is_role( $role ) ) {
			DebugLogger::warning(
				'Refused to assign a role that does not exist',
				array( 'role' => $role )
			);
			return null;
		}

		return $role;
	}

	/**
	 * Reports whether a role carries a capability that amounts to running the
	 * site.
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function is_too_powerful( string $role ): bool {
		$object = get_role( $role );

		if ( ! $object ) {
			return false;
		}

		foreach ( self::DANGEROUS_CAPABILITIES as $capability ) {
			if ( ! empty( $object->capabilities[ $capability ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether a role may be handed out by the webhook at all.
	 *
	 * The single rule the settings screen and the webhook both apply.
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function is_assignable_role( string $role ): bool {
		return '' !== $role
			&& ! in_array( $role, self::DISALLOWED_ROLES, true )
			&& ! self::is_too_powerful( $role );
	}
}
