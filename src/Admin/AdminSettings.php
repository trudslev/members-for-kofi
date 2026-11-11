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

use MembersForKofi\Webhook\Webhook;
use MembersForKofi\Webhook\VerificationToken;

use function register_setting;
use function add_settings_error;
use function get_editable_roles;

/**
 * The plugin's admin page: Settings, Members and Logs tabs.
 *
 * Each tab is its own URL (`?page=members-for-kofi&tab=…`), so a tab or a
 * filtered log can be linked and reloaded. The Members and Logs tabs live in
 * MembersTab and LogsTab; this class renders the page, the Settings tab and
 * the admin notices, and sanitizes the settings.
 *
 * @package MembersForKofi
 */
class AdminSettings {

	/**
	 * The page's tabs, in order.
	 *
	 * @var array<int,string>
	 */
	public const TABS = array( 'settings', 'members', 'logs' );

	/**
	 * Registers the page's handlers and assets.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		LogsTab::register();
	}

	/**
	 * Registers the plugin's setting and its sanitizer.
	 *
	 * The form is rendered by render_settings_tab(), not by Settings API
	 * sections, so only the setting itself is registered here.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'members_for_kofi_options',
			'members_for_kofi_options',
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
			)
		);
	}

	/**
	 * Enqueues the page's script and stylesheet, on this page only.
	 *
	 * @return void
	 */
	public function enqueue_admin_scripts(): void {
		$screen = get_current_screen();

		if ( ! isset( $screen->id ) || 'toplevel_page_members-for-kofi' !== $screen->id ) {
			return;
		}

		$version = get_file_data( MEMBERS_FOR_KOFI_PLUGIN_FILE, array( 'Version' => 'Version' ) )['Version'];

		wp_enqueue_style(
			'members-for-kofi-admin',
			plugins_url( 'assets/css/admin.css', dirname( __DIR__ ) ),
			array(),
			$version
		);

		wp_enqueue_script(
			'members-for-kofi-admin-settings',
			plugins_url( 'assets/js/admin-settings.js', dirname( __DIR__ ) ),
			array(),
			$version,
			true
		);

		wp_localize_script(
			'members-for-kofi-admin-settings',
			'kofiMembers',
			array(
				'copied' => __( 'Copied', 'members-for-kofi' ),
			)
		);
	}

	/**
	 * The tab the URL asks for.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which tab to display.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		// The log viewer used to link to its own tab name.
		if ( 'user_logs' === $tab ) {
			return 'logs';
		}

		return in_array( $tab, self::TABS, true ) ? $tab : 'settings';
	}

	/**
	 * URL of one of the page's tabs.
	 *
	 * @param string $tab Tab key.
	 * @return string
	 */
	public static function tab_url( string $tab ): string {
		$args = array( 'page' => 'members-for-kofi' );

		if ( 'settings' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the admin page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		$active = self::current_tab();
		$labels = array(
			'settings' => __( 'Settings', 'members-for-kofi' ),
			'members'  => __( 'Members', 'members-for-kofi' ),
			'logs'     => __( 'Logs', 'members-for-kofi' ),
		);
		?>
		<div class="wrap mfk-wrap">
			<h1><?php esc_html_e( 'Members for Ko-fi', 'members-for-kofi' ); ?></h1>
			<?php
			// WordPress prints Settings API messages by itself only on pages
			// under Settings. This is a top-level page, so without this call a
			// rejected save ("Verification Token is required", a disallowed
			// role) was silently dropped, and a good one got no confirmation.
			settings_errors();
			$this->render_cron_warning();
			?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Members for Ko-fi sections', 'members-for-kofi' ); ?>">
				<?php foreach ( $labels as $tab => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $tab ) ); ?>" class="nav-tab<?php echo $tab === $active ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $active ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'members' === $active ) {
				MembersTab::render();
			} elseif ( 'logs' === $active ) {
				LogsTab::render();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders the Settings tab.
	 *
	 * @return void
	 */
	public function render_settings_tab(): void {
		?>
		<form method="post" action="options.php" id="members-for-kofi-settings-form" class="mfk-settings">
			<?php settings_fields( 'members_for_kofi_options' ); ?>

			<section class="mfk-card" aria-labelledby="mfk-connection">
				<div class="mfk-card-head">
					<h2 id="mfk-connection"><?php esc_html_e( 'Ko-fi connection', 'members-for-kofi' ); ?></h2>
					<?php $this->render_connection_status(); ?>
				</div>
				<ol class="mfk-steps">
					<li><?php $this->render_webhook_url_field(); ?></li>
					<li><?php $this->render_verification_token_field(); ?></li>
					<li>
						<p class="mfk-step-title"><?php esc_html_e( 'Press "Send test" on Ko-fi', 'members-for-kofi' ); ?></p>
						<p class="description">
							<?php
							printf(
								// translators: %s is a link to the request log.
								esc_html__( 'It shows up in the status line above and in %s. A test never creates an account.', 'members-for-kofi' ),
								'<a href="' . esc_url( add_query_arg( 'log', 'requests', self::tab_url( 'logs' ) ) ) . '">' . esc_html__( 'the request log', 'members-for-kofi' ) . '</a>'
							);
							?>
						</p>
					</li>
				</ol>
			</section>

			<section class="mfk-card mfk-card-body" aria-labelledby="mfk-roles">
				<h2 id="mfk-roles"><?php esc_html_e( 'Who gets a role', 'members-for-kofi' ); ?></h2>
				<?php
				$this->render_only_subscriptions_field();
				$this->render_tier_role_map_field();
				$this->render_default_role_field();
				?>
			</section>

			<section class="mfk-card mfk-card-body" aria-labelledby="mfk-expiry">
				<h2 id="mfk-expiry"><?php esc_html_e( 'When membership ends', 'members-for-kofi' ); ?></h2>
				<p class="mfk-sentence">
					<?php
					$this->render_expiry_toggle_field();
					$this->render_role_expiry_field();
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Ko-fi subscriptions renew monthly, so 35 days leaves a few days\' grace. A role the user held before paying is never removed.', 'members-for-kofi' ); ?></p>
			</section>

			<section class="mfk-card mfk-card-body" aria-labelledby="mfk-log-history">
				<h2 id="mfk-log-history"><?php esc_html_e( 'Log history', 'members-for-kofi' ); ?></h2>
				<p class="mfk-sentence">
					<?php
					$this->render_auto_clear_logs_field();
					$this->render_log_retention_days_field();
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Untick to keep everything, for example when another tool reads the request log.', 'members-for-kofi' ); ?></p>
			</section>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Says whether Ko-fi's requests are arriving and being accepted.
	 *
	 * Based on the newest row of the request log, which also holds refused
	 * requests from anyone who finds the URL: a refusal says "the last
	 * request", not "Ko-fi".
	 *
	 * @return void
	 */
	public function render_connection_status(): void {
		$options = get_option( VerificationToken::OPTION );

		if ( '' === VerificationToken::stored_hash( $options ) ) {
			$class = 'mfk-status-error';
			$text  = __( 'Not connected: no token is saved, so Ko-fi\'s requests are refused.', 'members-for-kofi' );
		} else {
			$latest = LogsTab::latest_request();
			if ( null === $latest ) {
				$class = 'mfk-status-waiting';
				$text  = __( 'Waiting for Ko-fi: no request has arrived yet.', 'members-for-kofi' );
			} else {
				$ago = human_time_diff( LogsTab::to_unix( (string) $latest['timestamp'] ) );
				if ( '1' === (string) $latest['success'] ) {
					$class = 'mfk-status-ok';
					// translators: %s is a time span, e.g. "2 hours".
					$text = sprintf( __( 'Working: the last request from Ko-fi, %s ago, was accepted.', 'members-for-kofi' ), $ago );
				} else {
					$class = 'mfk-status-error';
					// translators: 1: a time span, e.g. "2 hours", 2: why it was refused, e.g. "the token doesn't match".
					$text = sprintf( __( 'The last request, %1$s ago, was refused: %2$s', 'members-for-kofi' ), $ago, LogsTab::refusal_reason( (string) $latest['error'] ) );
				}
			}
		}

		echo '<p class="mfk-status ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Step 1: the webhook URL to give Ko-fi, with a copy button.
	 *
	 * @return void
	 */
	public function render_webhook_url_field(): void {
		$link = '<a href="' . esc_url( 'https://ko-fi.com/manage/webhooks?src=sidemenu' ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Ko-fi\'s webhook settings', 'members-for-kofi' )
			. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'members-for-kofi' ) . '</span></a>';
		?>
		<label for="webhook-kofi-url" class="mfk-step-title"><?php esc_html_e( 'Give Ko-fi this webhook URL', 'members-for-kofi' ); ?></label>
		<div class="mfk-copy-row">
			<input type="text" id="webhook-kofi-url" value="<?php echo esc_url( self::webhook_url() ); ?>" readonly class="regular-text code">
			<button type="button" id="copy-webhook-url" class="button"><?php esc_html_e( 'Copy URL', 'members-for-kofi' ); ?></button>
			<span id="mfk-copy-status" class="mfk-copy-status" role="status"></span>
		</div>
		<p class="description">
			<?php
			// translators: %s is a link to Ko-fi's webhook settings.
			printf( esc_html__( 'Paste it into %s.', 'members-for-kofi' ), $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			?>
		</p>
		<?php
	}

	/**
	 * Step 2: the verification token. Write-only: the saved one is never shown.
	 *
	 * @return void
	 */
	public function render_verification_token_field(): void {
		$options     = get_option( 'members_for_kofi_options' );
		$fingerprint = VerificationToken::fingerprint( $options );
		?>
		<label for="mfk-token" class="mfk-step-title"><?php esc_html_e( 'Paste Ko-fi\'s verification token', 'members-for-kofi' ); ?></label>
		<?php if ( '' !== $fingerprint ) : ?>
			<p class="mfk-token-state">
				<?php esc_html_e( 'A token is saved', 'members-for-kofi' ); ?>
				<code><?php echo esc_html( $fingerprint ); ?></code>
				<span class="mfk-muted"><?php esc_html_e( 'fingerprint, to compare with another site', 'members-for-kofi' ); ?></span>
			</p>
		<?php else : ?>
			<p class="mfk-token-state mfk-status-error"><?php esc_html_e( 'No token is saved yet.', 'members-for-kofi' ); ?></p>
		<?php endif; ?>
		<?php if ( VerificationToken::has_conflict( $options ) ) : ?>
			<p class="mfk-status-error"><?php echo esc_html( self::token_conflict_message() ); ?></p>
		<?php endif; ?>
		<?php // Write-only: the saved token is never rendered back into the page, and only its hash is stored, so the value is always empty. ?>
		<input type="password" id="mfk-token" name="members_for_kofi_options[verification_token]" value="" class="regular-text" autocomplete="new-password"<?php echo '' !== $fingerprint ? ' placeholder="' . esc_attr__( 'Leave blank to keep the saved token', 'members-for-kofi' ) . '"' : ''; ?>>
		<p class="description"><?php esc_html_e( 'On Ko-fi\'s webhook page, open Advanced and copy the token. Only a hash is stored, so it can\'t be shown again.', 'members-for-kofi' ); ?></p>
		<?php
	}

	/**
	 * Which payments grant a role. Radios, so both answers are always sent.
	 *
	 * @return void
	 */
	public static function render_only_subscriptions_field(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$only    = ! empty( $options['only_subscriptions'] );
		?>
		<fieldset class="mfk-field">
			<legend class="mfk-field-title"><?php esc_html_e( 'Payments that grant a role', 'members-for-kofi' ); ?></legend>
			<label><input type="radio" name="members_for_kofi_options[only_subscriptions]" value="0"<?php checked( ! $only ); ?>> <?php esc_html_e( 'Donations and subscriptions', 'members-for-kofi' ); ?></label><br>
			<label><input type="radio" name="members_for_kofi_options[only_subscriptions]" value="1"<?php checked( $only ); ?>> <?php esc_html_e( 'Subscriptions only', 'members-for-kofi' ); ?> <span class="mfk-muted"><?php esc_html_e( '(one-off donations are logged and ignored)', 'members-for-kofi' ); ?></span></label>
		</fieldset>
		<?php
	}

	/**
	 * Options for a role select: assignable roles only.
	 *
	 * @param string $selected Selected role slug.
	 * @return string Escaped `<option>` elements.
	 */
	private function role_options( string $selected ): string {
		$html = '';

		foreach ( get_editable_roles() as $key => $details ) {
			if ( ! Webhook::is_assignable_role( $key ) ) {
				continue;
			}
			$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( translate_user_role( $details['name'] ) ) . '</option>';
		}

		return $html;
	}

	/**
	 * One row of the tier table.
	 *
	 * @param string $tier Tier name.
	 * @param string $role Role slug.
	 * @return string
	 */
	private function tier_row( string $tier, string $role ): string {
		return '<tr>'
			. '<td><input type="text" name="members_for_kofi_options[tier_role_map][tier][]" value="' . esc_attr( $tier ) . '" class="regular-text tier-name-input" aria-label="' . esc_attr__( 'Tier name on Ko-fi', 'members-for-kofi' ) . '"></td>'
			. '<td><select name="members_for_kofi_options[tier_role_map][role][]" class="tier-role-select" aria-label="' . esc_attr__( 'Role for this tier', 'members-for-kofi' ) . '">' . $this->role_options( $role ) . '</select></td>'
			. '<td class="mfk-actions"><button type="button" class="button-link button-link-delete remove-tier-role-row" aria-label="' . esc_attr__( 'Remove this tier', 'members-for-kofi' ) . '">' . esc_html__( 'Remove', 'members-for-kofi' ) . '</button></td>'
			. '</tr>';
	}

	/**
	 * The tier table, with tiers Ko-fi sent that are not mapped yet.
	 *
	 * @return void
	 */
	public function render_tier_role_map_field(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$map     = isset( $options['tier_role_map'] ) && is_array( $options['tier_role_map'] ) ? $options['tier_role_map'] : array();

		$rows = '';
		foreach ( $map as $tier => $role ) {
			$rows .= $this->tier_row( (string) $tier, (string) $role );
		}

		$unmapped = LogsTab::unmapped_tiers( array_map( 'strval', array_keys( $map ) ) );
		?>
		<div class="mfk-field">
			<p class="mfk-field-title"><?php esc_html_e( 'Ko-fi tiers', 'members-for-kofi' ); ?></p>
			<div class="mfk-table-wrap">
				<table id="tier-role-map-table" class="widefat mfk-table mfk-tier-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Tier name on Ko-fi', 'members-for-kofi' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Role it grants', 'members-for-kofi' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'members-for-kofi' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by tier_row(), which escapes every value. ?>
					</tbody>
				</table>
			</div>
			<template id="tier-role-row-template"><?php echo $this->tier_row( '', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in tier_row(). ?></template>
			<p class="mfk-inline">
				<button type="button" class="button" id="add-tier-role-row"><?php esc_html_e( 'Add tier', 'members-for-kofi' ); ?></button>
				<span class="mfk-muted"><?php esc_html_e( 'Names match whatever their capitalisation.', 'members-for-kofi' ); ?></span>
			</p>
			<?php if ( array() !== $unmapped ) : ?>
				<div class="mfk-unmapped">
					<span><?php esc_html_e( 'Tiers Ko-fi sent that aren\'t listed:', 'members-for-kofi' ); ?></span>
					<?php foreach ( $unmapped as $tier => $payments ) : ?>
						<button type="button" class="button mfk-add-unmapped" data-tier="<?php echo esc_attr( $tier ); ?>">
							<?php
							echo esc_html(
								sprintf(
									// translators: 1: a tier name, 2: how many payments named it.
									_n( 'Add "%1$s" (%2$d payment)', 'Add "%1$s" (%2$d payments)', $payments, 'members-for-kofi' ),
									$tier,
									$payments
								)
							);
							?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The role for payments no tier matches.
	 *
	 * @return void
	 */
	public function render_default_role_field(): void {
		$options  = get_option( 'members_for_kofi_options', array() );
		$selected = isset( $options['default_role'] ) && is_string( $options['default_role'] ) ? $options['default_role'] : '';
		?>
		<div class="mfk-field">
			<label for="mfk-default-role" class="mfk-field-title"><?php esc_html_e( 'Any other tier, or no tier', 'members-for-kofi' ); ?></label>
			<select id="mfk-default-role" name="members_for_kofi_options[default_role]">
				<option value=""><?php esc_html_e( '— No role —', 'members-for-kofi' ); ?></option>
				<?php echo $this->role_options( $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in role_options(). ?>
			</select>
			<p class="description"><?php esc_html_e( 'With "No role", those payers still get an account, but no role. Administrator and other roles that can run the site are never offered.', 'members-for-kofi' ); ?></p>
		</div>
		<?php
	}

	/**
	 * "Remove the role" checkbox: the start of the expiry sentence.
	 *
	 * @return void
	 */
	public static function render_expiry_toggle_field(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$checked = ! empty( $options['enable_expiry'] );
		echo '<input type="hidden" name="members_for_kofi_options[enable_expiry]" value="0">';
		echo '<input type="checkbox" id="members_for_kofi_enable_expiry" name="members_for_kofi_options[enable_expiry]" value="1"' . checked( $checked, true, false ) . '> ';
		echo '<label for="members_for_kofi_enable_expiry">' . esc_html__( 'Remove the role', 'members-for-kofi' ) . '</label> ';
	}

	/**
	 * "[35] days after the member's last payment": the end of the sentence.
	 *
	 * @return void
	 */
	public static function render_role_expiry_field(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$value   = isset( $options['role_expiry_days'] ) ? intval( $options['role_expiry_days'] ) : 35;
		echo '<label for="members_for_kofi_expiry_days" class="screen-reader-text">' . esc_html__( 'Days after the last payment', 'members-for-kofi' ) . '</label>';
		echo '<input type="number" id="members_for_kofi_expiry_days" name="members_for_kofi_options[role_expiry_days]" value="' . esc_attr( (string) $value ) . '" min="1" class="small-text"> ';
		echo '<span>' . esc_html__( 'days after the member\'s last payment', 'members-for-kofi' ) . '</span>';
	}

	/**
	 * "Delete log entries older than" checkbox.
	 *
	 * @return void
	 */
	public static function render_auto_clear_logs_field(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$checked = isset( $options['auto_clear_logs'] ) ? (bool) $options['auto_clear_logs'] : true;
		// A browser sends nothing for an unchecked box, and the sanitizer reads a
		// missing value as on (the default for installs that predate the
		// setting). Without this hidden "0", unticking the box never stuck.
		echo '<input type="hidden" name="members_for_kofi_options[auto_clear_logs]" value="0">';
		echo '<input type="checkbox" id="members_for_kofi_auto_clear_logs" name="members_for_kofi_options[auto_clear_logs]" value="1"' . checked( $checked, true, false ) . '> ';
		echo '<label for="members_for_kofi_auto_clear_logs">' . esc_html__( 'Delete log entries older than', 'members-for-kofi' ) . '</label> ';
	}

	/**
	 * "[30] days": the end of the log sentence.
	 *
	 * @return void
	 */
	public static function render_log_retention_days_field(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$value   = isset( $options['log_retention_days'] ) ? intval( $options['log_retention_days'] ) : 30;
		echo '<label for="members_for_kofi_log_retention_days" class="screen-reader-text">' . esc_html__( 'Number of Days to Keep Logs', 'members-for-kofi' ) . '</label>';
		echo '<input type="number" id="members_for_kofi_log_retention_days" name="members_for_kofi_options[log_retention_days]" value="' . esc_attr( (string) $value ) . '" min="1" class="small-text"> ';
		echo '<span>' . esc_html__( 'days', 'members-for-kofi' ) . '</span>';
	}

	/**
	 * The webhook address to give Ko-fi.
	 *
	 * `/webhook-kofi/` needs pretty permalinks to reach WordPress at all on
	 * some servers; the query form works everywhere, so it is what a site on
	 * plain permalinks is shown.
	 *
	 * @return string
	 */
	public static function webhook_url(): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'kofi_webhook', '1', home_url( '/' ) );
		}

		return home_url( '/webhook-kofi/' );
	}

	/**
	 * Warns when the plugin's scheduled tasks are not running.
	 *
	 * With WP-Cron disabled and no system cron, roles never expire -- free
	 * access for anyone who ever paid -- and logs are never pruned, with
	 * nothing to say so. An event more than a day overdue is that evidence.
	 *
	 * @return void
	 */
	public function render_cron_warning(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$hooks   = array( 'kofi_members_cleanup_logs' );

		if ( ! empty( $options['enable_expiry'] ?? true ) ) {
			$hooks[] = 'kofi_members_check_expired_roles';
		}

		foreach ( $hooks as $hook ) {
			$next = wp_next_scheduled( $hook );

			if ( false !== $next && $next < time() - DAY_IN_SECONDS ) {
				echo '<div class="notice notice-warning"><p>' . esc_html(
					sprintf(
						// translators: %s is a human-readable time span, e.g. "3 days".
						__( 'Scheduled tasks are not running: role expiry and log cleanup are %s overdue. Make sure WP-Cron runs, or that a system cron calls wp-cron.php.', 'members-for-kofi' ),
						human_time_diff( $next )
					)
				) . '</p></div>';
				return;
			}
		}
	}

	/**
	 * Points administrators at an account left behind by Ko-fi's test button.
	 *
	 * Before 1.3.0 a "Send test" webhook was processed like a payment, so a
	 * site that ever pressed it has a "Jo Example" account, usually holding the
	 * membership role. Shown where an administrator would see and act on it.
	 *
	 * @param string|null $screen_id Screen to check; defaults to the current one.
	 * @return void
	 */
	public static function render_test_account_notice( $screen_id = null ): void {
		if ( ! current_user_can( 'delete_users' ) ) {
			return;
		}

		// do_action( 'admin_notices' ) hands every callback an empty string, so
		// '' has to mean "the current screen" just like no argument does.
		if ( ! is_string( $screen_id ) || '' === $screen_id ) {
			$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screen_id = $screen ? (string) $screen->id : '';
		}

		if ( ! in_array( $screen_id, array( 'dashboard', 'users', 'toplevel_page_members-for-kofi' ), true ) ) {
			return;
		}

		$user = get_user_by( 'email', Webhook::KOFI_TEST_EMAIL );

		if ( ! $user ) {
			return;
		}

		$names = wp_roles()->get_names();
		$roles = array();
		foreach ( $user->roles as $slug ) {
			$roles[] = isset( $names[ $slug ] ) ? translate_user_role( $names[ $slug ] ) : $slug;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					// translators: 1: email address, 2: comma-separated role names, or "no role".
					__( 'Members for Ko-fi: an account was created by Ko-fi\'s "Send test" button (%1$s, role: %2$s). It is not a real supporter; earlier versions treated tests as payments. You can safely delete it.', 'members-for-kofi' ),
					Webhook::KOFI_TEST_EMAIL,
					array() !== $roles ? implode( ', ', $roles ) : __( 'none', 'members-for-kofi' )
				)
			),
			esc_url( get_edit_user_link( $user->ID ) ),
			esc_html__( 'Review the account', 'members-for-kofi' )
		);
	}

	/**
	 * Explains a stored hash and plaintext token that disagree.
	 *
	 * @return string
	 */
	private static function token_conflict_message(): string {
		return __( 'Members for Ko-fi: two different verification tokens are stored, and webhooks are checked against the hashed one. Re-enter your Ko-fi verification token in the settings to resolve this.', 'members-for-kofi' );
	}

	/**
	 * Warns administrators, on every admin screen, about conflicting tokens.
	 *
	 * The site is verifying against a hash nobody can inspect, and the debug
	 * log is off on most sites, so this is the only place it will be noticed.
	 * Saving a token clears it: the settings never store the plaintext key.
	 *
	 * @return void
	 */
	public static function render_token_conflict_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! VerificationToken::has_conflict( get_option( VerificationToken::OPTION ) ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( self::token_conflict_message() ),
			esc_url( admin_url( 'admin.php?page=members-for-kofi' ) ),
			esc_html__( 'Open settings', 'members-for-kofi' )
		);
	}

	/**
	 * Sanitizes the options for the Members for Ko-fi plugin.
	 *
	 * This function validates and sanitizes the input options
	 * provided by the user in the settings page.
	 *
	 * @param array $options The input options to sanitize.
	 * @return array The sanitized options.
	 */
	public function sanitize_options( array $options ): array {
		$stored        = get_option( 'members_for_kofi_options', array() );
		$errors        = array();
		$enable_expiry = ! empty( $options['enable_expiry'] );

		// The field is write-only: only a hash is stored, and the form never
		// shows it. A blank submission therefore means "keep the saved token",
		// never "clear it". sanitize_text_field() trims, so a token pasted with
		// stray whitespace is stored the way Ko-fi's real traffic arrives. A
		// submitted hash is never accepted: it can only come from this code.
		$submitted_token = isset( $options['verification_token'] ) && is_string( $options['verification_token'] )
			? sanitize_text_field( $options['verification_token'] )
			: '';
		// A blank save also converts a plaintext token left in the settings by
		// a restore from before 1.2.0, which webhooks no longer accept.
		$token_hash = '' !== $submitted_token ? VerificationToken::hash( $submitted_token ) : VerificationToken::stored_hash( $stored );
		if ( '' === $token_hash ) {
			$token_hash = VerificationToken::legacy_hash( $stored );
		}
		if ( '' === $token_hash ) {
			$errors[] = __( 'Verification Token is required.', 'members-for-kofi' );
		}

		$role_expiry_days = isset( $options['role_expiry_days'] ) ? absint( $options['role_expiry_days'] ) : ( $stored['role_expiry_days'] ?? 35 );
		if ( $enable_expiry && ( empty( $role_expiry_days ) || ! is_numeric( (string) $role_expiry_days ) ) ) {
			$errors[] = __( 'Role Expiry Days is required and must be a number when expiry is enabled.', 'members-for-kofi' );
		}

		if ( ! $enable_expiry && empty( $role_expiry_days ) ) {
			// Expiry is off and no usable number was sent: keep the stored one.
			$role_expiry_days = $stored['role_expiry_days'] ?? 35;
		}

		// Build tier map supporting both new parallel arrays and legacy associative array submission.
		$tier_map = array();
		if ( isset( $options['tier_role_map']['tier'] ) && is_array( $options['tier_role_map']['tier'] ) ) {
			foreach ( $options['tier_role_map']['tier'] as $index => $tier_raw ) {
				$tier = sanitize_text_field( $tier_raw );
				$role = isset( $options['tier_role_map']['role'][ $index ] ) ? sanitize_key( $options['tier_role_map']['role'][ $index ] ) : '';
				// Security: Explicitly reject disallowed roles (e.g., administrator).
				if ( $tier && $role && Webhook::is_assignable_role( $role ) ) {
					$tier_map[ $tier ] = $role;
				} elseif ( $tier && $role && ! Webhook::is_assignable_role( $role ) ) {
					$errors[] = sprintf(
						// translators: %s is the role name that was rejected.
						__( 'Security: Role "%s" cannot be assigned via webhook for security reasons.', 'members-for-kofi' ),
						$role
					);
				}
			}
		} elseif ( isset( $options['tier_role_map'] ) && is_array( $options['tier_role_map'] ) ) {
			// Legacy format already associative.
			foreach ( $options['tier_role_map'] as $tier_raw => $role_raw ) {
				$tier = sanitize_text_field( $tier_raw );
				$role = sanitize_key( $role_raw );
				// Security: Explicitly reject disallowed roles (e.g., administrator).
				if ( $tier && $role && Webhook::is_assignable_role( $role ) ) {
					$tier_map[ $tier ] = $role;
				} elseif ( $tier && $role && ! Webhook::is_assignable_role( $role ) ) {
					$errors[] = sprintf(
						// translators: %s is the role name that was rejected.
						__( 'Security: Role "%s" cannot be assigned via webhook for security reasons.', 'members-for-kofi' ),
						$role
					);
				}
			}
		}

		// Security: Validate default role is not in disallowed list.
		$default_role = isset( $options['default_role'] ) ? sanitize_key( $options['default_role'] ) : '';
		if ( $default_role && ! Webhook::is_assignable_role( $default_role ) ) {
			$errors[] = sprintf(
				// translators: %s is the role name that was rejected.
				__( 'Security: Role "%s" cannot be used as default role for security reasons.', 'members-for-kofi' ),
				$default_role
			);
			$default_role = ''; // Clear invalid default role.
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $error ) {
				add_settings_error( 'members_for_kofi_options', 'members_for_kofi_options_error', $error, 'error' );
			}
			return $stored; // Return previous values.
		}

		// Sanitize logging settings.
		$auto_clear_logs    = isset( $options['auto_clear_logs'] ) ? (bool) $options['auto_clear_logs'] : true;
		$log_retention_days = isset( $options['log_retention_days'] ) ? absint( $options['log_retention_days'] ) : 30;
		if ( $log_retention_days < 1 ) {
			$log_retention_days = 30;
		}

		return array(
			VerificationToken::HASH_KEY => $token_hash,
			'only_subscriptions'        => ! empty( $options['only_subscriptions'] ),
			'tier_role_map'             => $tier_map,
			'default_role'              => $default_role,
			'enable_expiry'             => $enable_expiry,
			'role_expiry_days'          => $role_expiry_days,
			'auto_clear_logs'           => $auto_clear_logs,
			'log_retention_days'        => $log_retention_days,
		);
	}
}
