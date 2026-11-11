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

use MembersForKofi\Membership;

/**
 * The Members tab: who has, or had, a membership through Ko-fi.
 *
 * Read-only, built on Membership::get(), which other plugins use too.
 *
 * @since 1.7.0
 */
class MembersTab {

	/**
	 * Members per page.
	 *
	 * @var int
	 */
	public const PER_PAGE = 25;

	/**
	 * Days before the end of access that count as "ending soon".
	 *
	 * @var int
	 */
	public const ENDING_SOON_DAYS = 7;

	/**
	 * The view the current URL asks for, sanitized.
	 *
	 * @param array|null $query Query arguments; defaults to `$_GET`.
	 * @return array{status:string,s:string,paged:int}
	 */
	public static function view( ?array $query = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only view state; each value is sanitized below.
		$query = null === $query ? wp_unslash( $_GET ) : $query;

		$status = isset( $query['status'] ) && in_array( $query['status'], array( 'active', 'ending', 'ended' ), true ) ? $query['status'] : 'all';

		return array(
			'status' => $status,
			's'      => isset( $query['s'] ) && is_string( $query['s'] ) ? sanitize_text_field( $query['s'] ) : '',
			'paged'  => isset( $query['paged'] ) ? max( 1, absint( $query['paged'] ) ) : 1,
		);
	}

	/**
	 * Everyone the plugin has given a membership, current or ended.
	 *
	 * Found by the user meta the webhook and the expiry job write, queried
	 * by meta key directly.
	 *
	 * @return array<int,array{user:\WP_User,membership:array,state:string}> Newest payment first.
	 */
	public static function members(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta_key lookup; admin page only.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
				'kofi_donation_assigned_role',
				Membership::LAST_ROLE_META
			)
		);

		$ids = array_map( 'intval', (array) $ids );
		if ( array() === $ids ) {
			return array();
		}

		cache_users( $ids );

		$members = array();
		foreach ( $ids as $id ) {
			$user       = get_userdata( $id );
			$membership = $user ? Membership::get( $id ) : null;
			if ( ! $user || null === $membership ) {
				continue;
			}
			$members[] = array(
				'user'       => $user,
				'membership' => $membership,
				'state'      => self::state( $membership ),
			);
		}

		usort(
			$members,
			static fn( $a, $b ) => (int) ( $b['membership']['last_payment_at'] ?? 0 ) <=> (int) ( $a['membership']['last_payment_at'] ?? 0 )
		);

		return $members;
	}

	/**
	 * Which filter a membership belongs to.
	 *
	 * @param array $membership From Membership::get().
	 * @return string 'active', 'ending' or 'ended'.
	 */
	public static function state( array $membership ): string {
		if ( 'active' !== $membership['status'] ) {
			return 'ended';
		}

		$expires = $membership['expires_at'];

		if ( null !== $expires && $expires - time() <= self::ENDING_SOON_DAYS * DAY_IN_SECONDS ) {
			return 'ending';
		}

		return 'active';
	}

	/**
	 * Members matching a view.
	 *
	 * @param array $members From members().
	 * @param array $view    From view().
	 * @return array
	 */
	public static function filter( array $members, array $view ): array {
		return array_values(
			array_filter(
				$members,
				static function ( $member ) use ( $view ) {
					$state = $member['state'];
					if ( 'active' === $view['status'] && 'ended' === $state ) {
						return false;
					}
					if ( in_array( $view['status'], array( 'ending', 'ended' ), true ) && $view['status'] !== $state ) {
						return false;
					}
					if ( '' !== $view['s'] ) {
						$user = $member['user'];
						return false !== stripos( $user->display_name, $view['s'] ) || false !== stripos( $user->user_email, $view['s'] );
					}
					return true;
				}
			)
		);
	}

	/**
	 * URL of the Members tab for a view.
	 *
	 * @param array $view View from view().
	 * @param array $with Arguments to change.
	 * @return string
	 */
	private static function url( array $view, array $with = array() ): string {
		$args = array_merge(
			array(
				'page'   => 'members-for-kofi',
				'tab'    => 'members',
				'status' => 'all' !== $view['status'] ? $view['status'] : null,
				's'      => '' !== $view['s'] ? $view['s'] : null,
			),
			$with
		);

		return add_query_arg( array_filter( $args, static fn( $v ) => null !== $v && '' !== $v ), admin_url( 'admin.php' ) );
	}

	/**
	 * A date in the site's format, or a fallback.
	 *
	 * @param int|null $time Unix time.
	 * @return string
	 */
	private static function date( ?int $time ): string {
		return null === $time ? __( 'Unknown', 'members-for-kofi' ) : (string) wp_date( get_option( 'date_format' ), $time );
	}

	/**
	 * Renders the Members tab.
	 *
	 * @param array|null $view View; defaults to the one in the URL.
	 * @return void
	 */
	public static function render( ?array $view = null ): void {
		$view    = $view ?? self::view();
		$members = self::members();
		$counts  = array(
			'all'    => count( $members ),
			'active' => count( array_filter( $members, static fn( $m ) => 'ended' !== $m['state'] ) ),
			'ending' => count( array_filter( $members, static fn( $m ) => 'ending' === $m['state'] ) ),
			'ended'  => count( array_filter( $members, static fn( $m ) => 'ended' === $m['state'] ) ),
		);
		$labels  = array(
			'all'    => __( 'All', 'members-for-kofi' ),
			'active' => __( 'Active', 'members-for-kofi' ),
			// translators: The members whose access ends within the next seven days.
			'ending' => __( 'Ending within 7 days', 'members-for-kofi' ),
			'ended'  => __( 'Ended', 'members-for-kofi' ),
		);

		$matching = self::filter( $members, $view );
		$pages    = max( 1, (int) ceil( count( $matching ) / self::PER_PAGE ) );
		$paged    = min( $view['paged'], $pages );
		$shown    = array_slice( $matching, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$names    = wp_roles()->get_names();
		?>
		<div class="mfk-logs-head">
			<ul class="subsubsub mfk-subsubsub">
				<?php
				$last = array_key_last( $labels );
				foreach ( $labels as $key => $label ) :
					?>
					<li><a href="
					<?php
					echo esc_url(
						self::url(
							$view,
							array(
								'status' => 'all' !== $key ? $key : null,
								'paged'  => null,
							)
						)
					);
					?>
									"<?php echo $view['status'] === $key ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?>)</span></a><?php echo $last !== $key ? ' |' : ''; ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="get" class="mfk-inline-search" role="search">
				<input type="hidden" name="page" value="members-for-kofi">
				<input type="hidden" name="tab" value="members">
				<?php if ( 'all' !== $view['status'] ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $view['status'] ); ?>">
				<?php endif; ?>
				<label for="mfk-member-search" class="screen-reader-text"><?php esc_html_e( 'Search members', 'members-for-kofi' ); ?></label>
				<input type="search" id="mfk-member-search" name="s" value="<?php echo esc_attr( $view['s'] ); ?>" placeholder="<?php esc_attr_e( 'Name or email', 'members-for-kofi' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search members', 'members-for-kofi' ); ?></button>
			</form>
		</div>

		<div class="mfk-table-wrap">
			<table class="widefat striped mfk-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Member', 'members-for-kofi' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role', 'members-for-kofi' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'members-for-kofi' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Member since', 'members-for-kofi' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last payment', 'members-for-kofi' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Access until', 'members-for-kofi' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $shown ) : ?>
						<tr><td colspan="6"><?php echo array() === $members ? esc_html__( 'Nobody has become a member through Ko-fi yet.', 'members-for-kofi' ) : esc_html__( 'No members match.', 'members-for-kofi' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $shown as $member ) :
						$user       = $member['user'];
						$membership = $member['membership'];
						$role       = (string) $membership['role'];
						$role_name  = isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
						$expires    = $membership['expires_at'];
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><strong><?php echo esc_html( $user->display_name ); ?></strong></a><br>
								<span class="mfk-muted"><?php echo esc_html( $user->user_email ); ?></span>
							</td>
							<td>
								<?php
								if ( 'ended' === $member['state'] ) {
									// translators: %s is the name of a role that was removed.
									echo '<span class="mfk-muted">' . esc_html( sprintf( __( '%s, removed', 'members-for-kofi' ), $role_name ) ) . '</span>';
								} else {
									echo esc_html( $role_name );
									if ( ! $membership['managed'] ) {
										echo '<br><span class="mfk-muted">' . esc_html__( 'Granted before payment; never removed', 'members-for-kofi' ) . '</span>';
									}
								}
								?>
							</td>
							<td>
								<?php
								if ( 'ended' === $member['state'] ) {
									echo '<span class="mfk-badge">' . esc_html__( 'Ended', 'members-for-kofi' ) . '</span>';
								} elseif ( 'ending' === $member['state'] ) {
									$days = max( 0, (int) ceil( ( (int) $expires - time() ) / DAY_IN_SECONDS ) );
									echo '<span class="mfk-badge mfk-badge-warning">' . esc_html(
										sprintf(
											// translators: %d is a number of days.
											_n( 'Ends in %d day', 'Ends in %d days', $days, 'members-for-kofi' ),
											$days
										)
									) . '</span>';
								} else {
									echo '<span class="mfk-badge mfk-badge-success">' . esc_html__( 'Active', 'members-for-kofi' ) . '</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( self::date( $membership['member_since'] ) ); ?></td>
							<td>
								<?php
								echo esc_html( self::date( $membership['last_payment_at'] ) );
								if ( null !== $membership['subscription'] ) {
									echo '<br><span class="mfk-muted">' . ( $membership['subscription'] ? esc_html__( 'subscription', 'members-for-kofi' ) : esc_html__( 'one-off', 'members-for-kofi' ) ) . '</span>';
								}
								?>
							</td>
							<td>
								<?php
								if ( null === $expires ) {
									echo 'ended' === $member['state'] ? '<span class="mfk-muted">–</span>' : esc_html__( 'No expiry', 'members-for-kofi' );
								} elseif ( 'ended' === $member['state'] ) {
									// translators: %s is a date.
									echo esc_html( sprintf( __( 'Ended %s', 'members-for-kofi' ), self::date( $expires ) ) );
								} else {
									echo esc_html( self::date( $expires ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php
		if ( $pages > 1 ) {
			echo '<nav class="mfk-pages" aria-label="' . esc_attr__( 'Pages', 'members-for-kofi' ) . '">';
			if ( $paged > 1 ) {
				echo '<a class="button" href="' . esc_url( self::url( $view, array( 'paged' => $paged > 2 ? $paged - 1 : null ) ) ) . '">' . esc_html__( 'Previous page', 'members-for-kofi' ) . '</a> ';
			}
			echo '<span>' . esc_html(
				sprintf(
					// translators: 1: the current page number, 2: the number of pages.
					__( 'Page %1$s of %2$s', 'members-for-kofi' ),
					number_format_i18n( $paged ),
					number_format_i18n( $pages )
				)
			) . '</span>';
			if ( $paged < $pages ) {
				echo ' <a class="button" href="' . esc_url( self::url( $view, array( 'paged' => $paged + 1 ) ) ) . '">' . esc_html__( 'Next page', 'members-for-kofi' ) . '</a>';
			}
			echo '</nav>';
		}
		?>
		<p class="mfk-muted"><?php esc_html_e( 'Cancelling happens on Ko-fi, not here.', 'members-for-kofi' ); ?></p>
		<?php
	}
}
