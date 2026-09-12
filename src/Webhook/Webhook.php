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
				$rejection->get_data()['error'] ?? 'Unauthorized'
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
	 * Constant-time by design: hash_equals() compares every byte, where !==
	 * short-circuits at the first
	 * difference, which leaks the length of a correct prefix through timing.
	 * The is_string() guard is not cosmetic -- hash_equals() raises a TypeError
	 * on anything else, and casting an array warns, so a payload sending a
	 * non-string token would otherwise break a public endpoint.
	 *
	 * @param array $body The decoded webhook payload.
	 * @return bool
	 */
	private function is_authenticated( array $body ): bool {
		$options  = get_option( 'members_for_kofi_options' );
		$expected = $options['verification_token'] ?? '';

		if ( empty( $expected ) || empty( $body['verification_token'] ) || ! is_string( $body['verification_token'] ) ) {
			return false;
		}

		return hash_equals( $expected, $body['verification_token'] );
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
	 * @param RequestLogger $logger Logger to record the attempt with.
	 * @param int           $status HTTP status the request earned.
	 * @param string        $error  Error message to return and log.
	 * @return \WP_REST_Response
	 */
	private function reject( RequestLogger $logger, int $status, string $error ): \WP_REST_Response {
		if ( $this->too_many_recent_failures() ) {
			return new \WP_REST_Response( array( 'error' => 'Too many requests' ), 429 );
		}

		$this->record_failure();

		$logger->log_request( array(), $status, false, $error );

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
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
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
		DebugLogger::info( 'Webhook received', array( 'body' => $body ) );

		if ( empty( $body['verification_token'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Missing verification token' ), 400 );
		}

		if ( ! $this->is_authenticated( $body ) ) {
			DebugLogger::warning( 'Invalid verification token' );
			return new \WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
		}

		if ( empty( $body['email'] ) || ! is_email( $body['email'] ) ) {
			DebugLogger::warning( 'Invalid or missing email' );
			return new \WP_REST_Response( array( 'error' => 'Invalid email' ), 400 );
		}

		$email     = sanitize_email( $body['email'] );
		$tier_name = sanitize_text_field( $body['tier_name'] ?? '' );
		$amount    = floatval( $body['amount'] ?? 0 );
		$currency  = sanitize_text_field( $body['currency'] ?? 'USD' );
		$user      = get_user_by( 'email', $email );

		$is_subscription    = $body['is_subscription_payment'] ?? false;
		$only_subscriptions = $options['only_subscriptions'] ?? false;

		$user_logger = new UserLogger();

		if ( ! $only_subscriptions || $is_subscription ) {
			if ( ! $user ) {
				$user_id = $this->create_user( $email );
				if ( is_wp_error( $user_id ) ) {
					DebugLogger::error( 'User creation failed', array( 'error' => $user_id->get_error_message() ) );
					return new \WP_REST_Response( array( 'error' => 'User creation failed' ), 500 );
				}
				$user = get_user_by( 'ID', $user_id );
				DebugLogger::info(
					'New user created',
					array(
						'user_id' => $user_id,
						'email'   => $email,
					)
				);

				// Log user creation.
				$user_logger->log_action( $user_id, $email, 'User created' );
			}

			$role = $this->resolve_role_from_tier( $tier_name, $options );
			if ( $role ) {
				// Only one Ko-fi role is tracked per user. Drop the previous one
				// first, or a tier change would leave it attached forever: it stops
				// being tracked, so expiry can never remove it and the donor keeps
				// privileges from a tier they no longer pay for.
				$previous_role = get_user_meta( $user->ID, 'kofi_donation_assigned_role', true );
				if ( $previous_role && $previous_role !== $role && in_array( $previous_role, $user->roles, true ) ) {
					$user->remove_role( $previous_role );
					$user_logger->log_role_removal( $user->ID, $email, $previous_role );
				}

				$user->add_role( $role );
				update_user_meta( $user->ID, 'kofi_donation_assigned_role', $role );
				update_user_meta( $user->ID, 'kofi_role_assigned_at', time() );
				DebugLogger::info(
					'Assigned role to user',
					array(
						'user_id' => $user->ID,
						'email'   => $email,
						'role'    => $role,
					)
				);

				// Log role assignment.
				$user_logger->log_role_assignment( $user->ID, $email, $role );
			} else {
				DebugLogger::info( 'No matching tier or default role for user', array( 'tier' => $tier_name ) );
			}

			// Log the donation.
			$user_logger->log_donation( $user->ID, $email, $amount, $currency );
			DebugLogger::info(
				'Donation logged',
				array(
					'user_id'  => $user->ID,
					'email'    => $email,
					'amount'   => $amount,
					'currency' => $currency,
				)
			);
		} else {
			// Subscription required but payment not a subscription.
			DebugLogger::info( 'Ignoring non-subscription payment due to only_subscriptions setting', array( 'email' => $email ) );
			// Still log the ignored event for visibility (user_id 0 when user absent).
			$user_logger->log_action( $user ? $user->ID : 0, $email, 'Ignored non-subscription payment' );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Creates a new WordPress user with the given email.
	 *
	 * @param string $email The email address of the user to create.
	 * @return int|\WP_Error The user ID on success, or a WP_Error object on failure.
	 */
	protected function create_user( $email ) {
		return wp_create_user( $email, wp_generate_password(), $email );
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

		// Security: Block disallowed roles.
		if ( in_array( $role, self::DISALLOWED_ROLES, true ) ) {
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
}
