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

namespace MembersForKofi\Admin;

defined( 'ABSPATH' ) || exit;

use MembersForKofi\EmailAliases;
use MembersForKofi\Webhook\Webhook;

/**
 * The Logs tab: member activity and requests from Ko-fi.
 *
 * Rendered on the server from the URL (log, outcome, search, page), so a
 * filtered view can be linked and the back button works. Read-only apart
 * from deleting a whole log, which goes through admin-post.php with a nonce
 * and a capability check.
 *
 * @since 1.7.0
 */
class LogsTab {

	/**
	 * Page sizes offered.
	 *
	 * @var array<int>
	 */
	public const PER_PAGE_OPTIONS = array( 10, 25, 50, 100 );

	/**
	 * Page size when none, or an invalid one, is given.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 25;

	/**
	 * The admin-post action that deletes a log.
	 *
	 * @var string
	 */
	public const DELETE_ACTION = 'members_for_kofi_delete_log';

	/**
	 * Registers the delete handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::DELETE_ACTION, array( self::class, 'handle_delete' ) );
	}

	/**
	 * The table behind a log.
	 *
	 * @param string $log 'requests' or 'activity'.
	 * @return string
	 */
	public static function table( string $log ): string {
		global $wpdb;

		return 'requests' === $log
			? $wpdb->prefix . 'members_for_kofi_request_logs'
			: $wpdb->prefix . 'members_for_kofi_user_logs';
	}

	/**
	 * The view the current URL asks for, sanitized.
	 *
	 * @param array|null $query Query arguments; defaults to `$_GET`.
	 * @return array{log:string,outcome:string,s:string,paged:int,per_page:int}
	 */
	public static function view( ?array $query = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only view state; each value is sanitized below.
		$query = null === $query ? wp_unslash( $_GET ) : $query;

		$log      = isset( $query['log'] ) && 'activity' === $query['log'] ? 'activity' : 'requests';
		$outcome  = isset( $query['outcome'] ) && in_array( $query['outcome'], array( 'accepted', 'refused' ), true ) ? $query['outcome'] : 'all';
		$per_page = isset( $query['per_page'] ) ? absint( $query['per_page'] ) : self::DEFAULT_PER_PAGE;

		return array(
			'log'      => $log,
			'outcome'  => 'requests' === $log ? $outcome : 'all',
			's'        => isset( $query['s'] ) && is_string( $query['s'] ) ? sanitize_text_field( $query['s'] ) : '',
			'paged'    => isset( $query['paged'] ) ? max( 1, absint( $query['paged'] ) ) : 1,
			'per_page' => in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ? $per_page : self::DEFAULT_PER_PAGE,
		);
	}

	/**
	 * Builds the WHERE clause and its values for a view.
	 *
	 * @param array $view View from view().
	 * @return array{0:string,1:array}
	 */
	private static function where( array $view ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $view['s'] ) {
			$like = '%' . $wpdb->esc_like( $view['s'] ) . '%';
			if ( 'requests' === $view['log'] ) {
				$where[] = '(email LIKE %s OR tier_name LIKE %s OR error LIKE %s)';
			} else {
				$where[] = '(email LIKE %s OR action LIKE %s OR role LIKE %s)';
			}
			array_push( $params, $like, $like, $like );
		}

		if ( 'requests' === $view['log'] && 'all' !== $view['outcome'] ) {
			$where[]  = 'success = %d';
			$params[] = 'accepted' === $view['outcome'] ? 1 : 0;
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Number of entries in a log, optionally filtered.
	 *
	 * @param array $view View from view().
	 * @return int
	 */
	public static function count( array $view ): int {
		global $wpdb;

		list( $where, $params ) = self::where( $view );
		$table                  = self::table( $view['log'] );
		$sql                    = 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '` WHERE ' . $where;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own table; $sql is literal SQL with placeholders, values bound below.
		$count = array() === $params ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * One page of entries, newest first.
	 *
	 * @param array $view View from view().
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $view ): array {
		global $wpdb;

		list( $where, $params ) = self::where( $view );
		$table                  = self::table( $view['log'] );
		$columns                = 'requests' === $view['log']
			? '`timestamp`, `email`, `tier_name`, `amount`, `currency`, `is_subscription`, `payload`, `status_code`, `success`, `error`'
			: '`timestamp`, `user_id`, `email`, `action`, `role`';
		$sql                    = 'SELECT ' . $columns . ' FROM `' . esc_sql( $table ) . '` WHERE ' . $where . ' ORDER BY `id` DESC LIMIT %d OFFSET %d';
		$params[]               = $view['per_page'];
		$params[]               = ( $view['paged'] - 1 ) * $view['per_page'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own table; $sql is literal SQL with placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The most recent request from anyone, for the connection status.
	 *
	 * @return array{timestamp:string,success:string,error:string}|null
	 */
	public static function latest_request(): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table; must be current, never cached.
		$row = $wpdb->get_row( 'SELECT `timestamp`, `success`, `error` FROM `' . esc_sql( self::table( 'requests' ) ) . '` ORDER BY `id` DESC LIMIT 1', ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Tiers Ko-fi sent that have no mapping, with how often.
	 *
	 * Read from the "Unmapped tier: …" lines the webhook writes to the
	 * activity log, so they disappear when the log is cleared.
	 *
	 * @param array<int,string> $mapped Tier names already mapped.
	 * @param int               $limit  Most to return.
	 * @return array<string,int> Tier name => payments.
	 */
	public static function unmapped_tiers( array $mapped, int $limit = 5 ): array {
		global $wpdb;

		$prefix = 'Unmapped tier: ';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT `action`, COUNT(*) AS `payments` FROM `' . esc_sql( self::table( 'activity' ) ) . '` WHERE `action` LIKE %s GROUP BY `action` ORDER BY `payments` DESC LIMIT 20',
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);

		$tiers = array();
		foreach ( (array) $rows as $row ) {
			$name = trim( substr( (string) $row['action'], strlen( $prefix ) ) );
			if ( '' === $name ) {
				continue;
			}
			foreach ( $mapped as $tier ) {
				if ( 0 === strcasecmp( (string) $tier, $name ) ) {
					continue 2;
				}
			}
			$tiers[ $name ] = ( $tiers[ $name ] ?? 0 ) + (int) $row['payments'];
		}

		return array_slice( $tiers, 0, $limit, true );
	}

	/**
	 * Why a request was refused, in words for the site owner.
	 *
	 * @param string $error Error as stored in the request log.
	 * @return string
	 */
	public static function refusal_reason( string $error ): string {
		switch ( $error ) {
			case 'Unauthorized: token mismatch':
			case 'Unauthorized':
				return __( 'the token doesn\'t match', 'members-for-kofi' );
			case 'Unauthorized: no token configured':
				return __( 'no token is saved', 'members-for-kofi' );
			case 'Invalid payload':
			case 'Malformed JSON payload':
			case 'Missing verification token':
			case 'Invalid email':
				return __( 'the request was malformed', 'members-for-kofi' );
			case 'User creation failed':
				return __( 'the account could not be created', 'members-for-kofi' );
		}

		return $error;
	}

	/**
	 * Unix time of a log timestamp, which is stored in the site's time zone.
	 *
	 * @param string $mysql `Y-m-d H:i:s` in the site's time zone.
	 * @return int
	 */
	public static function to_unix( string $mysql ): int {
		try {
			return ( new \DateTimeImmutable( $mysql, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * A log timestamp for people: "Today, 14:05", "Yesterday, 22:40", else the date.
	 *
	 * @param string $mysql `Y-m-d H:i:s` in the site's time zone.
	 * @return string
	 */
	public static function format_time( string $mysql ): string {
		$time = self::to_unix( $mysql );

		if ( 0 === $time ) {
			return $mysql;
		}

		$clock = wp_date( get_option( 'time_format' ), $time );
		$day   = wp_date( 'Y-m-d', $time );

		if ( wp_date( 'Y-m-d' ) === $day ) {
			// translators: %s is a time of day.
			return sprintf( __( 'Today, %s', 'members-for-kofi' ), $clock );
		}

		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
			// translators: %s is a time of day.
			return sprintf( __( 'Yesterday, %s', 'members-for-kofi' ), $clock );
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * URL of the Logs tab for a view, keeping the other filters.
	 *
	 * @param array $view View from view().
	 * @param array $with Arguments to change.
	 * @return string
	 */
	private static function url( array $view, array $with = array() ): string {
		$args = array_merge(
			array(
				'page'     => 'members-for-kofi',
				'tab'      => 'logs',
				'log'      => $view['log'],
				'outcome'  => 'all' !== $view['outcome'] ? $view['outcome'] : null,
				's'        => '' !== $view['s'] ? $view['s'] : null,
				'per_page' => self::DEFAULT_PER_PAGE !== $view['per_page'] ? $view['per_page'] : null,
			),
			$with
		);

		return add_query_arg( array_filter( $args, static fn( $v ) => null !== $v && '' !== $v ), admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the Logs tab.
	 *
	 * @param array|null $view View; defaults to the one in the URL.
	 * @return void
	 */
	public static function render( ?array $view = null ): void {
		$view  = $view ?? self::view();
		$total = self::count( $view );
		$pages = max( 1, (int) ceil( $total / $view['per_page'] ) );

		if ( $view['paged'] > $pages ) {
			$view['paged'] = $pages;
		}

		$all_requests = self::count(
			array_merge(
				$view,
				array(
					'log'     => 'requests',
					's'       => '',
					'outcome' => 'all',
				)
			)
		);
		$all_activity = self::count(
			array_merge(
				$view,
				array(
					'log'     => 'activity',
					's'       => '',
					'outcome' => 'all',
				)
			)
		);
		$rows         = self::rows( $view );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The log was deleted.', 'members-for-kofi' ) . '</p></div>';
		}
		?>
		<div class="mfk-logs-head">
			<ul class="subsubsub mfk-subsubsub">
				<li><a href="
				<?php
				echo esc_url(
					self::url(
						$view,
						array(
							'log'     => 'activity',
							'outcome' => null,
							's'       => null,
						)
					)
				);
				?>
								"<?php echo 'activity' === $view['log'] ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Member activity', 'members-for-kofi' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $all_activity ) ); ?>)</span></a> |</li>
				<li><a href="
				<?php
				echo esc_url(
					self::url(
						$view,
						array(
							'log' => 'requests',
							's'   => null,
						)
					)
				);
				?>
								"<?php echo 'requests' === $view['log'] ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Requests from Ko-fi', 'members-for-kofi' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $all_requests ) ); ?>)</span></a></li>
			</ul>
			<p class="mfk-muted">
				<?php
				// translators: %s is the site's time zone, e.g. "Europe/Copenhagen".
				echo esc_html( sprintf( __( 'Times are in the site\'s time zone (%s).', 'members-for-kofi' ), wp_timezone_string() ) );
				?>
			</p>
		</div>

		<form method="get" class="mfk-filters" role="search">
			<input type="hidden" name="page" value="members-for-kofi">
			<input type="hidden" name="tab" value="logs">
			<input type="hidden" name="log" value="<?php echo esc_attr( $view['log'] ); ?>">
			<?php if ( 'requests' === $view['log'] ) : ?>
				<p>
					<label for="mfk-outcome"><?php esc_html_e( 'Outcome', 'members-for-kofi' ); ?></label>
					<select id="mfk-outcome" name="outcome">
						<option value="all"<?php selected( $view['outcome'], 'all' ); ?>><?php esc_html_e( 'All outcomes', 'members-for-kofi' ); ?></option>
						<option value="accepted"<?php selected( $view['outcome'], 'accepted' ); ?>><?php esc_html_e( 'Accepted', 'members-for-kofi' ); ?></option>
						<option value="refused"<?php selected( $view['outcome'], 'refused' ); ?>><?php esc_html_e( 'Refused', 'members-for-kofi' ); ?></option>
					</select>
				</p>
			<?php endif; ?>
			<p class="mfk-grow">
				<label for="mfk-search">
					<?php
					if ( 'requests' === $view['log'] ) {
						esc_html_e( 'Search email, tier or reason', 'members-for-kofi' );
					} else {
						esc_html_e( 'Search email, action or role', 'members-for-kofi' );
					}
					?>
				</label>
				<input type="search" id="mfk-search" name="s" value="<?php echo esc_attr( $view['s'] ); ?>">
			</p>
			<p>
				<label for="mfk-per-page"><?php esc_html_e( 'Per page', 'members-for-kofi' ); ?></label>
				<select id="mfk-per-page" name="per_page">
					<?php foreach ( self::PER_PAGE_OPTIONS as $size ) : ?>
						<option value="<?php echo esc_attr( (string) $size ); ?>"<?php selected( $view['per_page'], $size ); ?>><?php echo esc_html( number_format_i18n( $size ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Filter', 'members-for-kofi' ); ?></button></p>
		</form>

		<div class="mfk-table-wrap">
			<?php
			if ( 'requests' === $view['log'] ) {
				self::render_requests( $rows, '' !== $view['s'] || 'all' !== $view['outcome'] );
			} else {
				self::render_activity( $rows, '' !== $view['s'] );
			}
			?>
		</div>

		<?php
		self::render_pagination( $view, $pages );
		self::render_delete( $view['log'], 'requests' === $view['log'] ? $all_requests : $all_activity );
	}

	/**
	 * The requests table.
	 *
	 * @param array $rows     Rows from rows().
	 * @param bool  $filtered Whether a filter is applied.
	 * @return void
	 */
	private static function render_requests( array $rows, bool $filtered ): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$map     = is_array( $options ) && isset( $options['tier_role_map'] ) && is_array( $options['tier_role_map'] ) ? $options['tier_role_map'] : array();
		$mapped  = isset( $map['tier'] ) && is_array( $map['tier'] ) ? $map['tier'] : array_keys( $map );
		?>
		<table class="widefat striped mfk-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Received', 'members-for-kofi' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Supporter', 'members-for-kofi' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tier', 'members-for-kofi' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Payment', 'members-for-kofi' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Outcome', 'members-for-kofi' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'members-for-kofi' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $rows ) : ?>
					<tr><td colspan="6"><?php echo $filtered ? esc_html__( 'No entries match.', 'members-for-kofi' ) : esc_html__( 'No entries yet.', 'members-for-kofi' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $row ) :
					$payload = (string) $row['payload'];
					$is_test = false !== strpos( $payload, Webhook::KOFI_TEST_TRANSACTION_ID );
					$email   = (string) $row['email'];
					$tier    = (string) $row['tier_name'];
					$success = '1' === (string) $row['success'];
					$known   = false;
					foreach ( $mapped as $name ) {
						if ( 0 === strcasecmp( (string) $name, $tier ) ) {
							$known = true;
						}
					}
					?>
					<tr<?php echo $success ? '' : ' class="mfk-refused"'; ?>>
						<td class="mfk-nowrap"><?php echo esc_html( self::format_time( (string) $row['timestamp'] ) ); ?></td>
						<td>
							<?php
							if ( $is_test ) {
								esc_html_e( 'Ko-fi test', 'members-for-kofi' );
							} elseif ( '' === $email ) {
								echo '<span class="mfk-muted">' . esc_html__( 'Not stored', 'members-for-kofi' ) . '</span>';
							} else {
								$user = EmailAliases::find_user( $email );
								echo $user
									? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $email ) . '</a>'
									: esc_html( $email );
							}
							?>
						</td>
						<td>
							<?php
							if ( '' === $tier ) {
								echo '<span class="mfk-muted">–</span>';
							} else {
								echo esc_html( $tier );
								if ( ! $known && $success && ! $is_test ) {
									echo ' <span class="mfk-badge mfk-badge-warning">' . esc_html__( 'not mapped', 'members-for-kofi' ) . '</span>';
								}
							}
							?>
						</td>
						<td>
							<?php
							if ( $is_test ) {
								echo '<span class="mfk-muted">' . esc_html__( 'Test, no account made', 'members-for-kofi' ) . '</span>';
							} elseif ( (float) $row['amount'] > 0 ) {
								$amount = number_format_i18n( (float) $row['amount'], 2 ) . ' ' . (string) $row['currency'];
								echo esc_html(
									'1' === (string) $row['is_subscription']
										// translators: %s is an amount with its currency, e.g. "5.00 EUR".
										? sprintf( __( '%s · subscription', 'members-for-kofi' ), $amount )
										// translators: %s is an amount with its currency, e.g. "5.00 EUR".
										: sprintf( __( '%s · one-off', 'members-for-kofi' ), $amount )
								);
							} else {
								echo '<span class="mfk-muted">–</span>';
							}
							?>
						</td>
						<td>
							<?php if ( $success ) : ?>
								<span class="mfk-badge mfk-badge-success"><?php esc_html_e( 'Accepted', 'members-for-kofi' ); ?></span>
							<?php else : ?>
								<span class="mfk-badge mfk-badge-error">
									<?php
									// translators: %s is why the request was refused, e.g. "the token doesn't match".
									echo esc_html( sprintf( __( 'Refused: %s', 'members-for-kofi' ), self::refusal_reason( (string) $row['error'] ) ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( '' !== $payload && '[]' !== $payload ) : ?>
								<details class="mfk-payload">
									<summary><?php esc_html_e( 'Payload', 'members-for-kofi' ); ?></summary>
									<pre><?php echo esc_html( self::pretty_json( $payload ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Pretty-prints stored JSON, or returns it as is when it is not JSON
	 * (a truncated payload).
	 *
	 * @param string $json Stored payload.
	 * @return string
	 */
	private static function pretty_json( string $json ): string {
		$data = json_decode( $json, true );

		if ( null === $data ) {
			return $json;
		}

		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The member activity table.
	 *
	 * @param array $rows     Rows from rows().
	 * @param bool  $filtered Whether a search is applied.
	 * @return void
	 */
	private static function render_activity( array $rows, bool $filtered ): void {
		$names = wp_roles()->get_names();
		?>
		<table class="widefat striped mfk-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'members-for-kofi' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Member', 'members-for-kofi' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What happened', 'members-for-kofi' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Role', 'members-for-kofi' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $rows ) : ?>
					<tr><td colspan="4"><?php echo $filtered ? esc_html__( 'No entries match.', 'members-for-kofi' ) : esc_html__( 'No entries yet.', 'members-for-kofi' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $row ) :
					$user = (int) $row['user_id'] > 0 ? get_userdata( (int) $row['user_id'] ) : false;
					$role = (string) $row['role'];
					?>
					<tr>
						<td class="mfk-nowrap"><?php echo esc_html( self::format_time( (string) $row['timestamp'] ) ); ?></td>
						<td>
							<?php
							if ( $user ) {
								echo '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a><br><span class="mfk-muted">' . esc_html( (string) $row['email'] ) . '</span>';
							} else {
								echo esc_html( (string) $row['email'] );
							}
							?>
						</td>
						<td><?php echo esc_html( (string) $row['action'] ); ?></td>
						<td><?php echo esc_html( isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * WordPress-style paging: first, previous, "Page x of y", next, last.
	 *
	 * @param array $view  View from view().
	 * @param int   $pages Number of pages.
	 * @return void
	 */
	public static function render_pagination( array $view, int $pages ): void {
		if ( $pages <= 1 ) {
			return;
		}

		$current = $view['paged'];
		$links   = array(
			'first' => array( 1, '«', __( 'First page', 'members-for-kofi' ), $current > 1 ),
			'prev'  => array( max( 1, $current - 1 ), '‹', __( 'Previous page', 'members-for-kofi' ), $current > 1 ),
			'next'  => array( min( $pages, $current + 1 ), '›', __( 'Next page', 'members-for-kofi' ), $current < $pages ),
			'last'  => array( $pages, '»', __( 'Last page', 'members-for-kofi' ), $current < $pages ),
		);

		$link = static function ( string $key ) use ( $links, $view ): string {
			list( $page, $symbol, $label, $enabled ) = $links[ $key ];
			if ( ! $enabled ) {
				return '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . esc_html( $symbol ) . '</span>';
			}
			return '<a class="button" href="' . esc_url( self::url( $view, array( 'paged' => $page > 1 ? $page : null ) ) ) . '"><span class="screen-reader-text">' . esc_html( $label ) . '</span><span aria-hidden="true">' . esc_html( $symbol ) . '</span></a>';
		};

		$input = '<label for="mfk-current-page" class="screen-reader-text">' . esc_html__( 'Current page', 'members-for-kofi' ) . '</label>'
			. '<input class="current-page" id="mfk-current-page" type="text" name="paged" value="' . esc_attr( (string) $current ) . '" size="' . esc_attr( (string) strlen( (string) $pages ) ) . '">';
		?>
		<form method="get" class="tablenav bottom mfk-tablenav">
			<?php
			foreach ( array( 'page', 'tab', 'log', 'outcome', 's', 'per_page' ) as $key ) {
				$value = 'page' === $key ? 'members-for-kofi' : ( 'tab' === $key ? 'logs' : (string) $view[ $key ] );
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
			}
			?>
			<div class="tablenav-pages">
				<nav class="pagination-links" aria-label="<?php esc_attr_e( 'Pages', 'members-for-kofi' ); ?>">
					<?php
					// Each piece is escaped where it is built.
					echo $link( 'first' ) . ' ' . $link( 'prev' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<span class="paging-input">';
					printf(
						// translators: 1: an input holding the current page number, 2: the number of pages.
						esc_html__( 'Page %1$s of %2$s', 'members-for-kofi' ),
						$input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
						'<span class="total-pages">' . esc_html( number_format_i18n( $pages ) ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inline.
					);
					echo '</span> ';
					echo $link( 'next' ) . ' ' . $link( 'last' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</nav>
			</div>
		</form>
		<?php
	}

	/**
	 * The "Delete this log" section.
	 *
	 * @param string $log   'requests' or 'activity'.
	 * @param int    $total Entries in the log.
	 * @return void
	 */
	private static function render_delete( string $log, int $total ): void {
		if ( 0 === $total ) {
			return;
		}

		$options = get_option( 'members_for_kofi_options', array() );
		$auto    = ! is_array( $options ) || ! isset( $options['auto_clear_logs'] ) || (bool) $options['auto_clear_logs'];
		$days    = is_array( $options ) && isset( $options['log_retention_days'] ) ? max( 1, (int) $options['log_retention_days'] ) : 30;
		$count   = number_format_i18n( $total );
		$is_req  = 'requests' === $log;
		$summary = $is_req
			// translators: %s is the number of entries.
			? sprintf( _n( 'Removes %s request entry. Member activity is kept.', 'Removes all %s request entries. Member activity is kept.', $total, 'members-for-kofi' ), $count )
			// translators: %s is the number of entries.
			: sprintf( _n( 'Removes %s activity entry. The request log is kept.', 'Removes all %s activity entries. The request log is kept.', $total, 'members-for-kofi' ), $count );
		$confirm = sprintf(
			// translators: %s is the number of entries.
			_n( 'Delete %s entry in this log? This can\'t be undone.', 'Delete all %s entries in this log? This can\'t be undone.', $total, 'members-for-kofi' ),
			$count
		);
		?>
		<section class="mfk-card mfk-delete" aria-labelledby="mfk-delete-heading">
			<div>
				<h2 id="mfk-delete-heading"><?php esc_html_e( 'Delete this log', 'members-for-kofi' ); ?></h2>
				<p class="mfk-muted">
					<?php
					echo esc_html( $summary );
					if ( $auto ) {
						echo ' ' . esc_html(
							sprintf(
								// translators: %d is a number of days.
								_n( 'Entries older than %d day are deleted automatically.', 'Entries older than %d days are deleted automatically.', $days, 'members-for-kofi' ),
								$days
							)
						);
					}
					?>
				</p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mfk-confirm="<?php echo esc_attr( $confirm ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>">
				<input type="hidden" name="log" value="<?php echo esc_attr( $log ); ?>">
				<?php wp_nonce_field( self::DELETE_ACTION ); ?>
				<button type="submit" class="button mfk-button-danger">
					<?php echo $is_req ? esc_html__( 'Delete request log…', 'members-for-kofi' ) : esc_html__( 'Delete activity log…', 'members-for-kofi' ); ?>
				</button>
			</form>
		</section>
		<?php
	}

	/**
	 * Deletes every entry in one log, then returns to it.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this resource.', 'members-for-kofi' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DELETE_ACTION );

		$log = isset( $_POST['log'] ) && 'activity' === sanitize_key( wp_unslash( $_POST['log'] ) ) ? 'activity' : 'requests';

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Emptying the plugin's own table on an administrator's request.
		$wpdb->query( 'DELETE FROM `' . esc_sql( self::table( $log ) ) . '`' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'members-for-kofi',
					'tab'     => 'logs',
					'log'     => $log,
					'deleted' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
