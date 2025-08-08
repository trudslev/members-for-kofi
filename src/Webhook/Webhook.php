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

use WP_User;
use MembersForKofi\Logging\LoggerFactory;
use Monolog\Logger;
use MembersForKofi\Logging\UserLogger;

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

	public const DISALLOWED_ROLES = array( 'administrator' );

	/**
	 * Logger instance for logging webhook events and errors.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor for the Webhook class.
	 *
	 * @param Logger|null $logger Optional logger instance for logging events.
	 */
	public function __construct( ?Logger $logger = null ) {
		$this->logger = $logger ?? LoggerFactory::get_logger();
	}

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
		if ( null === $data ) {
			if ( $request instanceof \WP_REST_Request ) {
				$data = $request->get_json_params();
			} else {
				parse_str( file_get_contents( 'php://input' ), $payload );
				if ( ! key_exists( 'data', $payload ) ) {
					return new \WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
				}
				$data = json_decode( $payload['data'], true );
			}
		}

		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
		}

		return $this->process( $data );
	}

	/**
	 * Processes the webhook payload and performs actions based on the data.
	 *
	 * @param array $body The webhook payload data.
	 * @return \WP_REST_Response The response object indicating success or failure.
	 */
	private function process( array $body ): \WP_REST_Response {
		$options = get_option( 'kofi_members_options' );
		$this->logger->info( 'Webhook received', array( 'body' => $body ) );

		if ( empty( $body['verification_token'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'Missing verification token' ), 400 );
		}

		$verification_token = $options['verification_token'] ?? '';
		if ( empty( $verification_token ) || $body['verification_token'] !== $verification_token ) {
			$this->logger->warning( 'Invalid verification token' );
			return new \WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
		}

		if ( empty( $body['email'] ) || ! is_email( $body['email'] ) ) {
			$this->logger->warning( 'Invalid or missing email' );
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
					$this->logger->error( 'User creation failed', array( 'error' => $user_id->get_error_message() ) );
					return new \WP_REST_Response( array( 'error' => 'User creation failed' ), 500 );
				}
				$user = get_user_by( 'ID', $user_id );
				$this->logger->info(
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
				$user->add_role( $role );
				update_user_meta( $user->ID, 'kofi_donation_assigned_role', $role );
				update_user_meta( $user->ID, 'kofi_role_assigned_at', time() );
				$this->logger->info(
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
				$this->logger->info( 'No matching tier or default role for user', array( 'tier' => $tier_name ) );
			}

			// Log the donation.
			$user_logger->log_donation( $user->ID, $email, $amount, $currency );
			$this->logger->info(
				'Donation logged',
				array(
					'user_id'  => $user->ID,
					'email'    => $email,
					'amount'   => $amount,
					'currency' => $currency,
				)
			);
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

		foreach ( $map['tier'] ?? array() as $index => $tier_name ) {
			if ( strcasecmp( $tier_name, $tier ) === 0 ) {
				return $map['role'][ $index ] ?? $default_role;
			}
		}

		return $default_role ? $default_role : null;
	}
}
