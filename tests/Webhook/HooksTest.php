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

use MembersForKofi\Cron\RoleExpiryChecker;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Tests\TestCase;
use MembersForKofi\Webhook\Webhook;

/**
 * The filters and actions the webhook offers other plugins (since 1.6.0).
 */
class HooksTest extends TestCase {

	/**
	 * Every hook this suite attaches to, removed after each test.
	 *
	 * @var array<string>
	 */
	private const HOOKS = array(
		'members_for_kofi_process_payment',
		'members_for_kofi_create_account',
		'members_for_kofi_role',
		'members_for_kofi_new_user_args',
		'members_for_kofi_user_created',
		'members_for_kofi_role_assigned',
		'members_for_kofi_role_removed',
		'members_for_kofi_donation_logged',
	);

	/**
	 * Calls recorded by the action spies, keyed by hook.
	 *
	 * @var array<string, array<int, array>>
	 */
	private array $calls = array();

	/**
	 * Configures the webhook with a Gold → author mapping.
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option(
			'members_for_kofi_options',
			array(
				'verification_token_sha256' => hash( 'sha256', 'fallback-token' ),
				'tier_role_map'             => array(
					'tier' => array( 'Gold', 'Silver' ),
					'role' => array( 'author', 'contributor' ),
				),
				'default_role'              => 'subscriber',
				'only_subscriptions'        => false,
				'enable_expiry'             => true,
				'role_expiry_days'          => 30,
			)
		);
	}

	/**
	 * Detaches every callback the test added.
	 */
	protected function tearDown(): void {
		foreach ( self::HOOKS as $hook ) {
			remove_all_filters( $hook );
		}

		parent::tearDown();
	}

	/**
	 * A payment payload as Ko-fi sends it.
	 *
	 * @param string $email Donor email.
	 * @param string $tier  Tier name.
	 * @param array  $extra Fields to override.
	 * @return array
	 */
	private function payment( string $email, string $tier = 'Gold', array $extra = array() ): array {
		return array_merge(
			array(
				'verification_token'      => 'fallback-token',
				'message_id'              => wp_generate_uuid4(),
				'kofi_transaction_id'     => wp_generate_uuid4(),
				'type'                    => 'Subscription',
				'email'                   => $email,
				'tier_name'               => $tier,
				'amount'                  => '5.00',
				'currency'                => 'EUR',
				'is_subscription_payment' => true,
			),
			$extra
		);
	}

	/**
	 * Sends a payment through the webhook.
	 *
	 * @param array $payload Payload.
	 * @return \WP_REST_Response
	 */
	private function send( array $payload ): \WP_REST_Response {
		return ( new Webhook() )->handle( null, $payload );
	}

	/**
	 * Records every call of an action.
	 *
	 * @param string $hook Action name.
	 * @param int    $args Number of arguments.
	 * @return void
	 */
	private function spy( string $hook, int $args ): void {
		add_action(
			$hook,
			function ( ...$received ) use ( $hook ) {
				$this->calls[ $hook ][] = $received;
			},
			10,
			$args
		);
	}

	/**
	 * User-log actions written for an email, oldest first.
	 *
	 * @param string $email Donor email.
	 * @return array<string>
	 */
	private function logged_actions( string $email ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'members_for_kofi_user_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion on the plugin's own table.
		return $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$table} WHERE email = %s ORDER BY id", $email ) );
	}

	/**
	 * With nothing hooked, a first payment still creates the account and
	 * grants the mapped role.
	 */
	public function test_default_behaviour_is_unchanged(): void {
		$response = $this->send( $this->payment( 'hooks-default@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$user = get_user_by( 'email', 'hooks-default@example.com' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( array( 'author' ), array_values( $user->roles ) );
	}

	/**
	 * A payment the filter refuses is answered 200 and leaves no trace but
	 * the log line.
	 */
	public function test_process_payment_false_does_nothing_and_answers_200(): void {
		add_filter( 'members_for_kofi_process_payment', '__return_false' );

		$response = $this->send( $this->payment( 'hooks-skip@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( get_user_by( 'email', 'hooks-skip@example.com' ) );
		$this->assertSame( array( 'Ignored by filter' ), $this->logged_actions( 'hooks-skip@example.com' ) );
	}

	/**
	 * Refusing account creation answers 200 with no account -- never a 500,
	 * which Ko-fi would retry indefinitely.
	 */
	public function test_create_account_false_creates_nobody_and_answers_200(): void {
		add_filter( 'members_for_kofi_create_account', '__return_false' );

		$response = $this->send( $this->payment( 'hooks-blocked@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );
		$this->assertFalse( get_user_by( 'email', 'hooks-blocked@example.com' ) );
		$this->assertSame( array( 'Account not created' ), $this->logged_actions( 'hooks-blocked@example.com' ) );
	}

	/**
	 * A refused message is not marked delivered, so once the block is lifted
	 * a redelivery of the same message is processed.
	 */
	public function test_a_refused_message_is_processed_when_redelivered_after_the_block_is_lifted(): void {
		$payload = $this->payment( 'hooks-unblocked@example.com' );

		add_filter( 'members_for_kofi_create_account', '__return_false' );
		$this->send( $payload );
		remove_all_filters( 'members_for_kofi_create_account' );

		$response = $this->send( $payload );

		$this->assertSame( 200, $response->get_status() );
		$this->assertInstanceOf( WP_User::class, get_user_by( 'email', 'hooks-unblocked@example.com' ) );
	}

	/**
	 * The filter is about creating accounts: a member who already has one is
	 * never asked about.
	 */
	public function test_create_account_is_not_asked_for_an_existing_member(): void {
		$this->create_user( array( 'user_email' => 'hooks-existing@example.com' ) );
		$asked = 0;
		add_filter(
			'members_for_kofi_create_account',
			function () use ( &$asked ) {
				++$asked;
				return false;
			}
		);

		$response = $this->send( $this->payment( 'hooks-existing@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $asked );
		$this->assertContains( 'author', get_user_by( 'email', 'hooks-existing@example.com' )->roles );
	}

	/**
	 * The filter receives the email and the payload, without the token.
	 */
	public function test_create_account_receives_the_payload_without_the_token(): void {
		$received = array();
		add_filter(
			'members_for_kofi_create_account',
			function ( $create, $email, $body ) use ( &$received ) {
				$received = array( $email, $body );
				return $create;
			},
			10,
			3
		);

		$this->send( $this->payment( 'hooks-args@example.com' ) );

		$this->assertSame( 'hooks-args@example.com', $received[0] );
		$this->assertSame( 'Gold', $received[1]['tier_name'] );
		$this->assertArrayNotHasKey( 'verification_token', $received[1] );
	}

	/**
	 * A filter that throws yields its default, and the payment goes through.
	 */
	public function test_a_throwing_filter_cannot_cost_a_payment(): void {
		add_filter(
			'members_for_kofi_create_account',
			static function () {
				throw new \RuntimeException( 'broken callback' );
			}
		);

		$response = $this->send( $this->payment( 'hooks-throws@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertInstanceOf( WP_User::class, get_user_by( 'email', 'hooks-throws@example.com' ) );
	}

	/**
	 * A site can hand out a different role than the mapping says.
	 */
	public function test_role_filter_can_change_the_role(): void {
		add_filter(
			'members_for_kofi_role',
			static function ( $role, $tier ) {
				return 'Gold' === $tier ? 'contributor' : $role;
			},
			10,
			2
		);

		$this->send( $this->payment( 'hooks-role@example.com' ) );

		$user = get_user_by( 'email', 'hooks-role@example.com' );
		$this->assertSame( array( 'contributor' ), array_values( $user->roles ) );
		$this->assertSame( 'contributor', get_user_meta( $user->ID, 'kofi_donation_assigned_role', true ) );
	}

	/**
	 * An empty role withholds it, as an unmapped tier with no default does.
	 */
	public function test_role_filter_can_withhold_the_role(): void {
		add_filter( 'members_for_kofi_role', '__return_empty_string' );

		$response = $this->send( $this->payment( 'hooks-norole@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$user = get_user_by( 'email', 'hooks-norole@example.com' );
		$this->assertSame( array(), array_values( $user->roles ) );
		$this->assertSame( '', get_user_meta( $user->ID, 'kofi_role_assigned_at', true ) );
	}

	/**
	 * A filter cannot do what the settings screen cannot: administrator,
	 * admin-level roles and unknown roles are refused and the configured role
	 * is kept.
	 *
	 * @dataProvider refused_roles
	 *
	 * @param mixed $returned What the filter returns.
	 */
	public function test_role_filter_cannot_grant_a_forbidden_or_unknown_role( $returned ): void {
		add_filter(
			'members_for_kofi_role',
			static function () use ( $returned ) {
				return $returned;
			}
		);

		$response = $this->send( $this->payment( 'hooks-refused@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$user = get_user_by( 'email', 'hooks-refused@example.com' );
		$this->assertSame( array( 'author' ), array_values( $user->roles ) );
	}

	/**
	 * Roles a filter must not be able to hand out, and malformed answers.
	 *
	 * @return array
	 */
	public function refused_roles(): array {
		return array(
			'administrator' => array( 'administrator' ),
			'unknown role'  => array( 'no-such-role' ),
			'not a string'  => array( array( 'editor' ) ),
		);
	}

	/**
	 * A refused role is recorded where the site owner looks.
	 */
	public function test_a_refused_role_is_logged(): void {
		add_filter(
			'members_for_kofi_role',
			static function () {
				return 'administrator';
			}
		);

		$this->send( $this->payment( 'hooks-refused-log@example.com' ) );

		$this->assertContains( 'Role from filter refused: administrator', $this->logged_actions( 'hooks-refused-log@example.com' ) );
	}

	/**
	 * Account arguments can be filtered, but never the role or the email.
	 */
	public function test_new_user_args_cannot_set_the_role_or_email(): void {
		add_filter(
			'members_for_kofi_new_user_args',
			static function ( $args ) {
				$args['first_name'] = 'Filtered';
				$args['role']       = 'administrator';
				$args['user_email'] = 'someone-else@example.com';
				return $args;
			}
		);

		$this->send( $this->payment( 'hooks-args-new@example.com' ) );

		$user = get_user_by( 'email', 'hooks-args-new@example.com' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'Filtered', $user->first_name );
		$this->assertNotContains( 'administrator', $user->roles );
		$this->assertSame( array( 'author' ), array_values( $user->roles ) );
		$this->assertFalse( get_user_by( 'email', 'someone-else@example.com' ) );
	}

	/**
	 * A non-array answer falls back to the default arguments.
	 */
	public function test_new_user_args_falls_back_on_a_wrong_type(): void {
		add_filter( 'members_for_kofi_new_user_args', '__return_null' );

		$this->send( $this->payment( 'hooks-args-null@example.com' ) );

		$this->assertInstanceOf( WP_User::class, get_user_by( 'email', 'hooks-args-null@example.com' ) );
	}

	/**
	 * A first payment fires user_created, role_assigned and donation_logged,
	 * each once, with the right arguments.
	 */
	public function test_a_first_payment_fires_each_action_once(): void {
		$this->spy( 'members_for_kofi_user_created', 3 );
		$this->spy( 'members_for_kofi_role_assigned', 3 );
		$this->spy( 'members_for_kofi_donation_logged', 5 );
		$this->spy( 'members_for_kofi_role_removed', 3 );

		$this->send( $this->payment( 'hooks-actions@example.com' ) );
		$user_id = get_user_by( 'email', 'hooks-actions@example.com' )->ID;

		$this->assertCount( 1, $this->calls['members_for_kofi_user_created'] );
		list( $id, $email, $body ) = $this->calls['members_for_kofi_user_created'][0];
		$this->assertSame( $user_id, $id );
		$this->assertSame( 'hooks-actions@example.com', $email );
		$this->assertArrayNotHasKey( 'verification_token', $body );

		$this->assertCount( 1, $this->calls['members_for_kofi_role_assigned'] );
		$this->assertSame( array( $user_id, 'author' ), array_slice( $this->calls['members_for_kofi_role_assigned'][0], 0, 2 ) );

		$this->assertCount( 1, $this->calls['members_for_kofi_donation_logged'] );
		$this->assertSame(
			array( $user_id, 'hooks-actions@example.com', 5.0, 'EUR' ),
			array_slice( $this->calls['members_for_kofi_donation_logged'][0], 0, 4 )
		);

		$this->assertArrayNotHasKey( 'members_for_kofi_role_removed', $this->calls );
	}

	/**
	 * A renewal fires no user_created.
	 */
	public function test_a_renewal_does_not_fire_user_created(): void {
		$this->send( $this->payment( 'hooks-renewal@example.com' ) );
		$this->spy( 'members_for_kofi_user_created', 3 );
		$this->spy( 'members_for_kofi_role_assigned', 3 );

		$this->send( $this->payment( 'hooks-renewal@example.com' ) );

		$this->assertArrayNotHasKey( 'members_for_kofi_user_created', $this->calls );
		$this->assertCount( 1, $this->calls['members_for_kofi_role_assigned'] );
	}

	/**
	 * Moving to another tier removes the old role and says why.
	 */
	public function test_a_tier_change_fires_role_removed(): void {
		$this->send( $this->payment( 'hooks-tier@example.com', 'Gold' ) );
		$this->spy( 'members_for_kofi_role_removed', 3 );

		$this->send( $this->payment( 'hooks-tier@example.com', 'Silver' ) );
		$user_id = get_user_by( 'email', 'hooks-tier@example.com' )->ID;

		$this->assertSame(
			array( array( $user_id, 'author', 'tier_change' ) ),
			$this->calls['members_for_kofi_role_removed']
		);
	}

	/**
	 * Expiry removes the role and says why.
	 */
	public function test_expiry_fires_role_removed(): void {
		$this->send( $this->payment( 'hooks-expiry@example.com' ) );
		$user_id = get_user_by( 'email', 'hooks-expiry@example.com' )->ID;
		update_user_meta( $user_id, 'kofi_role_assigned_at', strtotime( '-31 days' ) );
		$this->spy( 'members_for_kofi_role_removed', 3 );

		( new RoleExpiryChecker( new UserLogger() ) )->check_and_remove_expired_roles();

		$this->assertSame(
			array( array( $user_id, 'author', 'expired' ) ),
			$this->calls['members_for_kofi_role_removed']
		);
	}

	/**
	 * An action that throws is skipped; the payment still completes.
	 */
	public function test_a_throwing_action_cannot_cost_a_payment(): void {
		add_action(
			'members_for_kofi_user_created',
			static function () {
				throw new \RuntimeException( 'broken callback' );
			}
		);

		$response = $this->send( $this->payment( 'hooks-action-throws@example.com' ) );

		$this->assertSame( 200, $response->get_status() );
		$user = get_user_by( 'email', 'hooks-action-throws@example.com' );
		$this->assertSame( array( 'author' ), array_values( $user->roles ) );
	}
}
