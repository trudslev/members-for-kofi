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

use MembersForKofi\Admin\LogsTab;
use MembersForKofi\Admin\MembersTab;
use MembersForKofi\Logging\RequestLogger;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Membership;
use MembersForKofi\Tests\TestCase;
use MembersForKofi\Webhook\Webhook;

/**
 * The Logs and Members tabs (since 1.7.0).
 */
class AdminTabsTest extends TestCase {

	/**
	 * Configures a token and a Gold mapping.
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option(
			'members_for_kofi_options',
			array(
				'verification_token_sha256' => hash( 'sha256', 'tabs-token' ),
				'tier_role_map'             => array( 'Gold' => 'subscriber' ),
				'enable_expiry'             => true,
				'role_expiry_days'          => 35,
				'auto_clear_logs'           => true,
				'log_retention_days'        => 30,
			)
		);
	}

	/**
	 * Output of a callable.
	 *
	 * @param callable $render What renders.
	 * @return string
	 */
	private function render( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	/**
	 * Writes request-log rows: two accepted, one refused, one Ko-fi test.
	 *
	 * @return void
	 */
	private function seed_requests(): void {
		$logger = new RequestLogger();
		$logger->log_request(
			array(
				'email'                   => 'gold@example.com',
				'tier_name'               => 'Gold',
				'amount'                  => '5.00',
				'currency'                => 'EUR',
				'is_subscription_payment' => true,
			),
			200,
			true,
			''
		);
		$logger->log_request(
			array(
				'email'     => 'bronze@example.com',
				'tier_name' => 'Bronze',
				'amount'    => '3.00',
				'currency'  => 'EUR',
			),
			200,
			true,
			''
		);
		$logger->log_request( array(), 401, false, 'Unauthorized: token mismatch' );
		$logger->log_request(
			array(
				'email'               => Webhook::KOFI_TEST_EMAIL,
				'kofi_transaction_id' => Webhook::KOFI_TEST_TRANSACTION_ID,
			),
			200,
			true,
			''
		);
	}

	/**
	 * The view is read from the URL and anything unexpected falls back.
	 */
	public function test_the_log_view_is_sanitized(): void {
		$view = LogsTab::view(
			array(
				'log'      => 'activity',
				'outcome'  => 'refused',
				's'        => '<b>x</b>',
				'paged'    => '-4',
				'per_page' => '7',
			)
		);

		$this->assertSame( 'activity', $view['log'] );
		$this->assertSame( 'all', $view['outcome'], 'The activity log has no outcome filter.' );
		$this->assertSame( 'x', $view['s'] );
		$this->assertSame( 4, $view['paged'], 'absint() turns -4 into 4; never below 1.' );
		$this->assertSame( LogsTab::DEFAULT_PER_PAGE, $view['per_page'] );

		$this->assertSame( 'requests', LogsTab::view( array( 'log' => 'nope' ) )['log'] );
		$this->assertSame( 'all', LogsTab::view( array( 'outcome' => array( 'refused' ) ) )['outcome'] );
	}

	/**
	 * The outcome filter separates accepted from refused requests.
	 */
	public function test_the_outcome_filter_counts_and_lists_the_right_rows(): void {
		$this->seed_requests();

		$this->assertSame( 4, LogsTab::count( LogsTab::view( array() ) ) );
		$this->assertSame( 3, LogsTab::count( LogsTab::view( array( 'outcome' => 'accepted' ) ) ) );
		$this->assertSame( 1, LogsTab::count( LogsTab::view( array( 'outcome' => 'refused' ) ) ) );
		$this->assertSame( 1, LogsTab::count( LogsTab::view( array( 's' => 'bronze' ) ) ) );

		$refused = LogsTab::rows( LogsTab::view( array( 'outcome' => 'refused' ) ) );
		$this->assertSame( 'Unauthorized: token mismatch', $refused[0]['error'] );
	}

	/**
	 * Requests read as words: outcome, Ko-fi test, unmapped tier, payment.
	 */
	public function test_requests_render_in_plain_words(): void {
		$this->seed_requests();

		$output = $this->render( static fn() => LogsTab::render( LogsTab::view( array() ) ) );

		$this->assertStringContainsString( 'Refused: the token doesn&#039;t match', $output );
		$this->assertStringContainsString( 'Ko-fi test', $output );
		$this->assertStringContainsString( 'Test, no account made', $output );
		$this->assertStringContainsString( 'not mapped', $output, 'Bronze has no mapping.' );
		$this->assertSame( 1, substr_count( $output, 'not mapped' ), 'Gold is mapped.' );
		$this->assertStringContainsString( '· subscription', $output );
		$this->assertStringContainsString( '· one-off', $output );
		$this->assertStringContainsString( '<details class="mfk-payload">', $output );
	}

	/**
	 * Paging is "Page x of y" with four links, never a button per page.
	 */
	public function test_paging_does_not_print_a_button_per_page(): void {
		$logger = new UserLogger();
		for ( $i = 0; $i < 35; $i++ ) {
			$logger->log_action( 0, "paging{$i}@example.com", 'User created' );
		}

		$output = $this->render(
			static fn() => LogsTab::render(
				LogsTab::view(
					array(
						'log'      => 'activity',
						'per_page' => '10',
						'paged'    => '2',
					)
				)
			)
		);

		$this->assertMatchesRegularExpression( '/Page <label[^>]*>.*?<\/label><input class="current-page"[^>]*value="2"[^>]*> of <span class="total-pages">4<\/span>/s', $output );
		$nav = substr( $output, (int) strpos( $output, '<nav class="pagination-links"' ) );
		$nav = substr( $nav, 0, (int) strpos( $nav, '</nav>' ) );
		$this->assertSame( 4, substr_count( $nav, '<a class="button"' ), 'First, previous, next and last; no button per page.' );
		$this->assertStringContainsString( 'paged=3', $nav, 'Next goes to page 3.' );
		$this->assertStringContainsString( 'paged=4', $nav, 'Last goes to page 4.' );
	}

	/**
	 * Refusal reasons in words, and an unknown one passed through.
	 */
	public function test_refusal_reasons(): void {
		$this->assertSame( 'no token is saved', LogsTab::refusal_reason( 'Unauthorized: no token configured' ) );
		$this->assertSame( 'the request was malformed', LogsTab::refusal_reason( 'Malformed JSON payload' ) );
		$this->assertSame( 'the account could not be created', LogsTab::refusal_reason( 'User creation failed' ) );
		$this->assertSame( 'Something new', LogsTab::refusal_reason( 'Something new' ) );
	}

	/**
	 * Times read "Today, …" and "Yesterday, …".
	 */
	public function test_times_are_relative_to_today(): void {
		$this->assertStringStartsWith( 'Today, ', LogsTab::format_time( current_time( 'mysql' ) ) );
		$this->assertStringStartsWith( 'Yesterday, ', LogsTab::format_time( wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$this->assertStringNotContainsString( 'day,', LogsTab::format_time( wp_date( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * The delete section names the log it empties.
	 */
	public function test_the_delete_section_names_its_log(): void {
		$this->seed_requests();

		$output = $this->render( static fn() => LogsTab::render( LogsTab::view( array() ) ) );

		$this->assertStringContainsString( 'Removes all 4 request entries. Member activity is kept.', $output );
		$this->assertStringContainsString( 'Entries older than 30 days are deleted automatically.', $output );
		$this->assertStringContainsString( 'name="action" value="' . LogsTab::DELETE_ACTION . '"', $output );
		$this->assertStringContainsString( 'name="_wpnonce"', $output );
		$this->assertStringContainsString( 'Delete request log…', $output );
	}

	/**
	 * Creates a member with a tracked role paid for `$days_ago` days ago.
	 *
	 * @param string $email    Email.
	 * @param int    $days_ago Days since the last payment.
	 * @param string $name     Display name.
	 * @return int
	 */
	private function member( string $email, int $days_ago, string $name = 'Supporter' ): int {
		$id = $this->create_user(
			array(
				'user_email'   => $email,
				'role'         => 'subscriber',
				'display_name' => $name,
			)
		);
		update_user_meta( $id, 'kofi_donation_assigned_role', 'subscriber' );
		update_user_meta( $id, 'kofi_role_assigned_at', time() - $days_ago * DAY_IN_SECONDS );
		update_user_meta( $id, Membership::MEMBER_SINCE_META, time() - 400 * DAY_IN_SECONDS );

		return $id;
	}

	/**
	 * Members are found and sorted into active, ending soon and ended.
	 */
	public function test_members_are_classified(): void {
		$active = $this->member( 'active-member@example.com', 1 );
		$ending = $this->member( 'ending-member@example.com', 31 );
		$ended  = $this->create_user( array( 'user_email' => 'ended-member@example.com' ) );
		Membership::record_end( $ended, 'subscriber', time() - 60 * DAY_IN_SECONDS, time() - 25 * DAY_IN_SECONDS );
		$this->create_user( array( 'user_email' => 'not-a-member@example.com' ) );

		$states = array();
		foreach ( MembersTab::members() as $member ) {
			$states[ $member['user']->ID ] = $member['state'];
		}

		$this->assertSame( 'active', $states[ $active ] ?? null );
		$this->assertSame( 'ending', $states[ $ending ] ?? null );
		$this->assertSame( 'ended', $states[ $ended ] ?? null );
		$this->assertCount( 3, $states, 'Someone who never paid is not listed.' );

		$members = MembersTab::members();
		$this->assertCount( 2, MembersTab::filter( $members, MembersTab::view( array( 'status' => 'active' ) ) ), 'Active includes those ending soon.' );
		$this->assertCount( 1, MembersTab::filter( $members, MembersTab::view( array( 'status' => 'ending' ) ) ) );
		$this->assertCount( 1, MembersTab::filter( $members, MembersTab::view( array( 'status' => 'ended' ) ) ) );
		$this->assertCount( 1, MembersTab::filter( $members, MembersTab::view( array( 's' => 'ENDING-member' ) ) ) );
	}

	/**
	 * The Members tab shows status words, and escapes names.
	 */
	public function test_the_members_tab_renders(): void {
		global $wpdb;

		$id = $this->member( 'render-member@example.com', 31 );
		// Written past wp_insert_user(), which strips tags: the tab must escape
		// whatever the table holds, not rely on how it got there.
		$wpdb->update( $wpdb->users, array( 'display_name' => '<script>alert(1)</script>' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		clean_user_cache( $id );

		$output = $this->render( static fn() => MembersTab::render( MembersTab::view( array() ) ) );

		$this->assertStringContainsString( 'render-member@example.com', $output );
		$this->assertMatchesRegularExpression( '/Ends in [34] days/', $output );
		$this->assertStringNotContainsString( '<script>alert(1)', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	/**
	 * With nobody to list, the tab says so.
	 */
	public function test_an_empty_members_tab_says_so(): void {
		$output = $this->render( static fn() => MembersTab::render( MembersTab::view( array() ) ) );

		$this->assertStringContainsString( 'Nobody has become a member through Ko-fi yet.', $output );
	}
}
