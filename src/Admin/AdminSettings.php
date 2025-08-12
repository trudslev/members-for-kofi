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

use MembersForKofi\Webhook\Webhook;

use function add_settings_section;
use function add_settings_field;
use function register_setting;
use function add_settings_error;
use function get_editable_roles;

/**
 * Handles the admin settings for the Members for Ko-fi plugin.
 *
 * This class is responsible for registering and rendering the settings
 * for the plugin in the WordPress admin dashboard.
 *
 * @package MembersForKofi
 */
class AdminSettings {
	/**
	 * Resolve the current logs table name, handling legacy name mismatch.
	 *
	 * @return string Fully qualified table name with prefix.
	 */
	private function get_logs_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'members_for_kofi_user_logs';
	}

	/**
	 * Constructor for the AdminSettings class.
	 *
	 * Initializes the class by adding the necessary WordPress actions.
	 */
	public function __construct() {
		// Register settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Enqueue admin scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Register AJAX handlers.
		add_action( 'wp_ajax_members_for_kofi_pagination', array( $this, 'handle_pagination' ) );
		add_action( 'wp_ajax_members_for_kofi_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'wp_ajax_members_for_kofi_update_rows_per_page', array( $this, 'handle_update_rows_per_page' ) );
		add_action( 'wp_ajax_members_for_kofi_filter_logs', array( $this, 'handle_filter_logs' ) );
		add_action( 'wp_ajax_members_for_kofi_refresh_logs', array( $this, 'handle_refresh_logs' ) );
	}


	/**
	 * Registers the settings for the Members for Ko-fi plugin.
	 *
	 * This function sets up the settings sections and fields for the plugin
	 * in the WordPress admin dashboard.
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

		// General tab.
		add_settings_section( 'members_for_kofi_general', __( 'General Settings', 'members-for-kofi' ), '__return_null', 'members-for-kofi' );
		add_settings_field( 'verification_token', __( 'Verification Token', 'members-for-kofi' ), array( $this, 'render_verification_token_field' ), 'members-for-kofi', 'members_for_kofi_general' );
		add_settings_field( 'only_subscriptions', __( 'Only Accept Subscriptions', 'members-for-kofi' ), array( $this, 'render_only_subscriptions_field' ), 'members-for-kofi', 'members_for_kofi_general' );
		add_settings_field( 'tier_role_map', __( 'Tier to Role Mapping', 'members-for-kofi' ), array( $this, 'render_tier_role_map_field' ), 'members-for-kofi', 'members_for_kofi_general' );
		add_settings_field( 'default_role', __( 'Default Role (if no match)', 'members-for-kofi' ), array( $this, 'render_default_role_field' ), 'members-for-kofi', 'members_for_kofi_general' );
		add_settings_field( 'enable_expiry', __( 'Enable Expiry', 'members-for-kofi' ), array( $this, 'render_expiry_toggle_field' ), 'members-for-kofi', 'members_for_kofi_general' );
		add_settings_field( 'role_expiry_days', __( 'Role Expiry (days)', 'members-for-kofi' ), array( $this, 'render_role_expiry_field' ), 'members-for-kofi', 'members_for_kofi_general' );
	}

	/**
	 * Enqueues the admin JavaScript file for the Members for Ko-fi settings page.
	 *
	 * @return void
	 */
	public function enqueue_admin_scripts(): void {
		$screen = get_current_screen();

		if ( isset( $screen->id ) && 'toplevel_page_members-for-kofi' === $screen->id ) {
			wp_enqueue_script(
				'members-for-kofi-admin-settings',
				plugins_url( 'assets/js/admin-settings.js', dirname( __DIR__ ) ),
				array(),
				'1.0.6',
				true
			);

			wp_localize_script(
				'members-for-kofi-admin-settings',
				'kofiMembers',
				array(
					'ajaxurl'          => admin_url( 'admin-ajax.php' ),
					'paginationNonce'  => wp_create_nonce( 'members_for_kofi_pagination' ),
					'clearLogsNonce'   => wp_create_nonce( 'members_for_kofi_clear_logs' ),
					'rowsPerPageNonce' => wp_create_nonce( 'members_for_kofi_update_rows_per_page' ),
					'filterNonce'      => wp_create_nonce( 'members_for_kofi_filter_logs' ),
					'refreshNonce'     => wp_create_nonce( 'members_for_kofi_refresh_logs' ),
					'clearLogsConfirm' => __( 'Are you sure you want to clear all logs? This action cannot be undone.', 'members-for-kofi' ),
					'errorMessage'     => __( 'An error occurred. Please try again.', 'members-for-kofi' ),
				)
			);
		}
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

		$verification_token = isset( $options['verification_token'] ) ? sanitize_text_field( $options['verification_token'] ) : '';
		if ( '' === $verification_token ) {
			$errors[] = __( 'Verification Token is required.', 'members-for-kofi' );
		}

		$role_expiry_days = isset( $options['role_expiry_days'] ) ? absint( $options['role_expiry_days'] ) : ( $stored['role_expiry_days'] ?? 35 );
		if ( $enable_expiry && ( empty( $role_expiry_days ) || ! is_numeric( (string) $role_expiry_days ) ) ) {
			$errors[] = __( 'Role Expiry Days is required and must be a number when expiry is enabled.', 'members-for-kofi' );
		}

		if ( ! $enable_expiry ) {
			// Preserve existing stored value if expiry disabled, fallback to default.
			$role_expiry_days = $stored['role_expiry_days'] ?? 35;
		}

		// Build tier map supporting both new parallel arrays and legacy associative array submission.
		$tier_map = array();
		if ( isset( $options['tier_role_map']['tier'] ) && is_array( $options['tier_role_map']['tier'] ) ) {
			foreach ( $options['tier_role_map']['tier'] as $index => $tier_raw ) {
				$tier = sanitize_text_field( $tier_raw );
				$role = isset( $options['tier_role_map']['role'][ $index ] ) ? sanitize_key( $options['tier_role_map']['role'][ $index ] ) : '';
				if ( $tier && $role ) {
					$tier_map[ $tier ] = $role;
				}
			}
		} elseif ( isset( $options['tier_role_map'] ) && is_array( $options['tier_role_map'] ) ) {
			// Legacy format already associative.
			foreach ( $options['tier_role_map'] as $tier_raw => $role_raw ) {
				$tier = sanitize_text_field( $tier_raw );
				$role = sanitize_key( $role_raw );
				if ( $tier && $role ) {
					$tier_map[ $tier ] = $role;
				}
			}
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $error ) {
				add_settings_error( 'members_for_kofi_options', 'members_for_kofi_options_error', $error, 'error' );
			}
			return $stored; // Return previous values.
		}

		return array(
			'verification_token' => $verification_token,
			'only_subscriptions' => ! empty( $options['only_subscriptions'] ),
			'tier_role_map'      => $tier_map,
			'default_role'       => isset( $options['default_role'] ) ? sanitize_key( $options['default_role'] ) : '',
			'enable_expiry'      => $enable_expiry,
			'role_expiry_days'   => $role_expiry_days,
		);
	}

	/**
	 * Renders the verification token field in the settings page.
	 *
	 * This function outputs the HTML for the verification token input field,
	 * allowing users to enter the token required for webhook verification.
	 *
	 * @return void
	 */
	public function render_verification_token_field(): void {
		$options     = get_option( 'members_for_kofi_options' );
		$webhook_url = home_url( '/webhook-kofi/' );

		// Verification Token Input.
		echo '<input type="text" name="members_for_kofi_options[verification_token]" value="' . esc_attr( $options['verification_token'] ?? '' ) . '" class="regular-text">';

		// Description for Verification Token.
		$description = sprintf(
			// translators: %s is a link to the Ko-fi Webhooks Management page.
			esc_html__( 'This token is used to verify incoming webhook requests from Ko-fi. Paste the verification token from this page: %s and open the Advanced box.', 'members-for-kofi' ),
			'<a href="' . esc_url( 'https://ko-fi.com/manage/webhooks?src=sidemenu' ) . '" target="_blank">' . esc_html__( 'Ko-fi Webhooks Management', 'members-for-kofi' ) . '</a>'
		);
		echo '<p class="description">' . wp_kses(
			$description,
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
				),
			)
		) . '</p>';

		// Webhook URL Textbox and Copy Button.
		echo '<div style="margin-top: 20px;">';
		echo '<label for="webhook-kofi-url" style="font-weight: bold; display: block; margin-bottom: 5px;">' . esc_html__( 'Webhook URL:', 'members-for-kofi' ) . '</label>';
		echo '<div style="display: flex; align-items: center; width: 500px;">';
		echo '<input type="text" id="webhook-kofi-url" value="' . esc_url( $webhook_url ) . '" readonly style="flex: 1; margin-right: 10px;" class="regular-text">';
		echo '<button type="button" id="copy-webhook-url" class="button button-secondary">' . esc_html__( 'Copy to Clipboard', 'members-for-kofi' ) . '</button>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Use this URL to configure your Ko-fi webhook.', 'members-for-kofi' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Renders the "Only Accept Subscriptions" field in the settings page.
	 *
	 * This function outputs the HTML for the checkbox that allows users
	 * to enable or disable role assignment only for active subscribers.
	 *
	 * @return void
	 */
	public static function render_only_subscriptions_field(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$checked = ! empty( $options['only_subscriptions'] );
		echo '<label><input type="checkbox" name="members_for_kofi_options[only_subscriptions]" value="1" ' . checked( $checked, true, false ) . '> Only assign roles to subscribers.</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, users must be active subscribers to receive a role.', 'members-for-kofi' ) . '</p>';
	}

	/**
	 * Renders the Tier to Role Mapping field in the settings page.
	 *
	 * This function outputs the HTML for mapping Ko-fi tiers to WordPress roles,
	 * allowing users to assign roles based on Ko-fi tier names.
	 *
	 * @return void
	 */
	public function render_tier_role_map_field(): void {
		$options          = get_option( 'members_for_kofi_options', array() );
		$map              = isset( $options['tier_role_map'] ) && is_array( $options['tier_role_map'] ) ? $options['tier_role_map'] : array();
		$roles            = get_editable_roles();
		$disallowed_roles = Webhook::DISALLOWED_ROLES;

		echo '<table id="tier-role-map-table" class="form-table" style="margin-top:0">';
		echo '<thead><tr><th>' . esc_html__( 'Ko-fi Tier', 'members-for-kofi' ) . '</th><th>' . esc_html__( 'Role', 'members-for-kofi' ) . '</th><th></th></tr></thead><tbody>';

		if ( ! empty( $map ) ) {
			foreach ( $map as $tier => $role ) {
				$tier_val = esc_attr( $tier );
				$role_val = esc_attr( $role );
				echo '<tr>';
				echo '<td><input type="text" name="members_for_kofi_options[tier_role_map][tier][]" value="' . esc_attr( $tier_val ) . '" class="regular-text"></td>';
				echo '<td><select name="members_for_kofi_options[tier_role_map][role][]" class="regular-text">';
				foreach ( $roles as $key => $details ) {
					if ( in_array( $key, $disallowed_roles, true ) ) {
						continue;
					}
					echo '<option value="' . esc_attr( $key ) . '"' . selected( $role_val, $key, false ) . '>' . esc_html( $details['name'] ) . '</option>';
				}
				echo '</select></td>';
				echo '<td><button type="button" class="button remove-tier-role-row">×</button></td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		echo '<template id="tier-role-row-template">';
		echo '<tr>';
		echo '<td><input type="text" name="members_for_kofi_options[tier_role_map][tier][]" class="regular-text tier-name-input"></td>';
		echo '<td><select name="members_for_kofi_options[tier_role_map][role][]" class="regular-text tier-role-select">';
		foreach ( $roles as $key => $details ) {
			if ( in_array( $key, $disallowed_roles, true ) ) {
				continue;
			}
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $details['name'] ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><button type="button" class="button remove-tier-role-row">×</button></td>';
		echo '</tr>';
		echo '</template>';

		echo '<p><button type="button" class="button" id="add-tier-role-row">' . esc_html__( 'Add Tier Mapping', 'members-for-kofi' ) . '</button></p>';
		echo '<p><em>' . esc_html__( 'Map Ko-fi tiers to WordPress roles. Only exact tier names will be matched.', 'members-for-kofi' ) . '</em></p>';
	}

	/**
	 * Renders the default role field in the settings page.
	 *
	 * This function outputs the HTML for selecting a default WordPress role
	 * to assign to users when no tier matches or tiers are not used.
	 *
	 * @return void
	 */
	public function render_default_role_field(): void {
		$options  = get_option( 'members_for_kofi_options', array() );
		$roles    = get_editable_roles();
		$selected = $options['default_role'] ?? array();

		echo '<select name="members_for_kofi_options[default_role]">';
		echo '<option value="">' . esc_html__( '— No default —', 'members-for-kofi' ) . '</option>';
		foreach ( $roles as $role_key => $role_details ) {
			echo '<option value="' . esc_attr( $role_key ) . '"' . selected( $role_key, $selected, false ) . '>' . esc_html( $role_details['name'] ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'If no tier matches, or if you are not using tiers, this role will be assigned to the user.', 'members-for-kofi' ) . '</p>';
	}

	/**
	 * Renders the "Enable Expiry" toggle field in the settings page.
	 *
	 * This function outputs the HTML for the checkbox that allows users
	 * to enable or disable role expiry after a set number of days.
	 *
	 * @return void
	 */
	public static function render_expiry_toggle_field(): void {
		$options = get_option( 'members_for_kofi_options', array() );
		$checked = ! empty( $options['enable_expiry'] );
		echo '<label><input type="checkbox" name="members_for_kofi_options[enable_expiry]" value="1" ' . checked( $checked, true, false ) . '></label>';
		echo '<p class="description">If checked, role will be removed after the set number of days.</p>';
	}

	/**
	 * Renders the role expiry field in the settings page.
	 *
	 * This function outputs the HTML for the input field that allows users
	 * to specify the number of days a role remains active for non-subscribers.
	 *
	 * @return void
	 */
	public static function render_role_expiry_field(): void {
		$options       = get_option( 'members_for_kofi_options', array() );
		$value         = isset( $options['role_expiry_days'] ) ? intval( $options['role_expiry_days'] ) : 35;
		$expiry_enable = ! empty( $options['enable_expiry'] );
		echo '<input type="number" id="members_for_kofi_expiry_days" name="members_for_kofi_options[role_expiry_days]" value="' . esc_attr( $value ) . '" min="1" ' . disabled( ! $expiry_enable, true, false ) . ' class="small-text">';
		echo '<p class="description">' . esc_html__( 'Number of days the role remains active (only used if expiry is enabled).', 'members-for-kofi' ) . '</p>';
	}

	/**
	 * Renders the log level field in the settings page.
	 *
	 * This function outputs the HTML for selecting the minimum severity
	 * of log messages to record in the plugin's log file.
	 *
	 * @return void
	 */
	// Deprecated: log level field removed with lightweight logger; method retained intentionally removed.

	/**
	 * Renders the settings page for the Members for Ko-fi plugin.
	 * This function outputs the HTML for the settings page in the WordPress admin dashboard,
	 * allowing users to configure the plugin's settings.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		$active_tab = 'general'; // Default; JS will switch without reload.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Members for Ko-fi Settings', 'members-for-kofi' ); ?></h1>
			<h2 class="nav-tab-wrapper" role="tablist">
				<a href="#" role="tab" data-tab="general" aria-selected="true" class="nav-tab nav-tab-active">
					<?php esc_html_e( 'General', 'members-for-kofi' ); ?>
				</a>
				<a href="#" role="tab" data-tab="user_logs" aria-selected="false" class="nav-tab">
					<?php esc_html_e( 'User Logs', 'members-for-kofi' ); ?>
				</a>
			</h2>
			<input type="hidden" id="active_tab" value="general" />
			<form method="post" action="options.php" id="members-for-kofi-general-form">
				<?php settings_fields( 'members_for_kofi_options' ); ?>
				<div id="members-for-kofi-tab-general" class="members-for-kofi-tab" style="">
					<h2><?php esc_html_e( 'General Settings', 'members-for-kofi' ); ?></h2>
					<table class="form-table">
						<?php do_settings_fields( 'members-for-kofi', 'members_for_kofi_general' ); ?>
					</table>
					<?php submit_button(); ?>
				</div>
			</form>
			<div id="members-for-kofi-tab-user_logs" class="members-for-kofi-tab" style="display:none;">
				<h2><?php esc_html_e( 'User Logs', 'members-for-kofi' ); ?></h2>
				<?php $this->render_user_logs_tab(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the "User Logs" tab in the settings page.
	 *
	 * This function outputs the HTML for displaying user logs and includes a button to clear logs.
	 *
	 * @param int|null $paged         The current page number, or null to auto-determine.
	 * @param int      $rows_per_page The number of rows to display per page.
	 * @return void
	 */
	public function render_user_logs_tab( int $paged = null, int $rows_per_page = 10 ): void {
		global $wpdb;

		$table_name = $this->get_logs_table_name();
		$total_logs = get_transient( 'members_for_kofi_total_logs' );

		if ( false === $total_logs || 0 === (int) $total_logs ) {
			// Recalculate if not cached or cached as zero (could be stale from prior table mismatch).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Counting rows in plugin-owned table.
			$total_logs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table_name ) . '`' );
			// Minimal debug retained: recount happened (verbose details removed).
			\MembersForKofi\Logging\DebugLogger::debug( 'User logs recount executed' );
			set_transient( 'members_for_kofi_total_logs', $total_logs, MINUTE_IN_SECONDS );
		}

		$total_pages = ceil( $total_logs / $rows_per_page );

		?>
		<!-- Logs Table Container -->
		<div id="logs-controls" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:10px;">
			<div>
				<label for="kofi-members-log-search" style="display:block;"><?php esc_html_e( 'Search (email / action / role)', 'members-for-kofi' ); ?></label>
				<input type="search" id="kofi-members-log-search" style="min-width:280px;" />
			</div>
			<div>
				<button type="button" class="button" id="kofi-members-log-search-btn"><?php esc_html_e( 'Search', 'members-for-kofi' ); ?></button>
				<button type="button" class="button" id="kofi-members-log-reset-btn"><?php esc_html_e( 'Reset', 'members-for-kofi' ); ?></button>
			</div>
			<div>
				<button type="button" class="button" id="kofi-members-log-refresh-btn"><?php esc_html_e( 'Refresh', 'members-for-kofi' ); ?></button>
			</div>
		</div>
		<div id="logs-table-container" data-current-search="">
			<?php $this->render_logs_table( $paged, $rows_per_page ); ?>
		</div>

		<?php
	}

	/**
	 * Handles the AJAX request for pagination in the user logs tab.
	 *
	 * This function verifies the user's permissions, processes the pagination request,
	 * and returns the rendered logs table for the requested page.
	 *
	 * @return void
	 */
	public function handle_pagination(): void {
		// Verify the nonce for security.
		check_ajax_referer( 'members_for_kofi_pagination', '_ajax_nonce' );
		// Minimal debug: pagination requested.
		\MembersForKofi\Logging\DebugLogger::debug( 'AJAX pagination request' );

		// Verify the current user has permission to view logs.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to access this resource.', 'members-for-kofi' ) );
		}

		$paged  = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		// Ensure the paged parameter is valid.
		if ( $paged < 1 ) {
			wp_send_json_error( __( 'Invalid page number.', 'members-for-kofi' ) );
		}

		// Render only the logs table for the requested page.
		ob_start();
		$this->render_logs_table( $paged, 10, $search );
		$content = ob_get_clean();

		wp_send_json_success( $content );
	}

	/**
	 * Handles the AJAX request to clear logs.
	 *
	 * @return void
	 */
	public function handle_clear_logs(): void {
		// Verify the nonce for security.
		check_ajax_referer( 'members_for_kofi_clear_logs', '_ajax_nonce' );
		\MembersForKofi\Logging\DebugLogger::debug( 'AJAX clear logs request' );

		global $wpdb;
		$table_name = $this->get_logs_table_name();

		// Check if the logs table exists and clear it.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Truncating plugin-owned table.
			$wpdb->query( 'DELETE FROM `' . esc_sql( $table_name ) . '`' );
			delete_transient( 'members_for_kofi_total_logs' ); // Clear the cached total logs count.

			// Render the updated logs table (which will now show "No logs available").
			ob_start();
			$this->render_logs_table();
			$content = ob_get_clean();

			wp_send_json_success( $content );
		} else {
			wp_send_json_error( __( 'Logs table does not exist.', 'members-for-kofi' ) );
		}
	}

	/**
	 * Handles the AJAX request to update rows per page.
	 *
	 * @return void
	 */
	public function handle_update_rows_per_page(): void {
		check_ajax_referer( 'members_for_kofi_update_rows_per_page', '_ajax_nonce' );
		\MembersForKofi\Logging\DebugLogger::debug( 'AJAX rows per page update request' );

		$rows_per_page = isset( $_POST['rows_per_page'] ) ? absint( $_POST['rows_per_page'] ) : 10;
		$search        = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		ob_start();
		$this->render_logs_table( null, $rows_per_page, $search );
		$content = ob_get_clean();

		wp_send_json_success( $content );
	}

	/**
	 * Handles AJAX log filtering.
	 */
	public function handle_filter_logs(): void {
		check_ajax_referer( 'members_for_kofi_filter_logs', '_ajax_nonce' );
		\MembersForKofi\Logging\DebugLogger::debug( 'AJAX filter logs request' );
		$search        = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$paged         = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
		$rows_per_page = isset( $_POST['rows_per_page'] ) ? absint( $_POST['rows_per_page'] ) : 10;
		ob_start();
		$this->render_logs_table( $paged, $rows_per_page, $search );
		$content = ob_get_clean();
		wp_send_json_success( $content );
	}

	/**
	 * Handles AJAX refresh (no search alteration; just reload current page).
	 */
	public function handle_refresh_logs(): void {
		check_ajax_referer( 'members_for_kofi_refresh_logs', '_ajax_nonce' );
		\MembersForKofi\Logging\DebugLogger::debug( 'AJAX refresh logs request' );
		$paged         = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
		$rows_per_page = isset( $_POST['rows_per_page'] ) ? absint( $_POST['rows_per_page'] ) : 10;
		$search        = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		ob_start();
		$this->render_logs_table( $paged, $rows_per_page, $search );
		$content = ob_get_clean();
		wp_send_json_success( $content );
	}

	/**
	 * Renders the logs table for the user logs tab.
	 *
	 * This function outputs the HTML for displaying the user logs in a table,
	 * including pagination links.
	 *
	 * @param int|null $paged         The current page number, or null to auto-determine.
	 * @param int      $rows_per_page The number of rows to display per page.
	 * @return void
	 */
	/**
	 * Render logs table.
	 *
	 * @param int|null $paged Page number.
	 * @param int      $rows_per_page Rows per page.
	 * @param string   $search Search filter.
	 */
	public function render_logs_table( int $paged = null, int $rows_per_page = 10, string $search = '' ): void {
		global $wpdb;

		$table_name   = $this->get_logs_table_name();
		$current_page = $paged ?? 1; // Simplified; no $_GET fallback (avoids nonce sniff concerns).
		$offset       = ( $current_page - 1 ) * $rows_per_page;

		$use_cache  = ( '' === $search );
		$total_logs = $use_cache ? get_transient( 'members_for_kofi_total_logs' ) : false;
		$where_sql  = 'WHERE 1=1';
		$params     = array();
		$like       = '';
		if ( '' !== $search ) {
			$like       = '%' . $wpdb->esc_like( $search ) . '%';
			$where_sql .= ' AND (email LIKE %s OR action LIKE %s OR role LIKE %s)';
			$params     = array( $like, $like, $like );
		}

		if ( false === $total_logs ) {
			if ( empty( $params ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Counting plugin-owned table.
				$total_logs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table_name ) . '`' );
			} else {
				$query      = 'SELECT COUNT(*) FROM `' . esc_sql( $table_name ) . '` ' . $where_sql;
				$total_logs = (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) );
			}
			if ( ! empty( $wpdb->last_error ) ) {
				\MembersForKofi\Logging\DebugLogger::error(
					'DB error counting logs',
					array(
						'error'      => $wpdb->last_error,
						'last_query' => $wpdb->last_query,
					)
				);
			}
			if ( $use_cache ) {
				set_transient( 'members_for_kofi_total_logs', $total_logs, MINUTE_IN_SECONDS );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Controlled table name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned table query.
		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Unfiltered query.
			$logs = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT `timestamp`, `user_id`, `email`, `action`, `role` FROM `' . esc_sql( $table_name ) . '` ORDER BY `timestamp` DESC LIMIT %d OFFSET %d',
					$rows_per_page,
					$offset
				),
				ARRAY_A
			);
			// Debug removed (noise).
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Filtered query.
			$query = 'SELECT `timestamp`, `user_id`, `email`, `action`, `role` FROM `' . esc_sql( $table_name ) . '` ' . $where_sql . ' ORDER BY `timestamp` DESC LIMIT %d OFFSET %d';
			$logs  = $wpdb->get_results(
				$wpdb->prepare(
					$query,
					array_merge( $params, array( $rows_per_page, $offset ) )
				),
				ARRAY_A
			);
			// Debug removed (noise).
		}
		if ( empty( $logs ) && (int) $total_logs > 0 ) {
			\MembersForKofi\Logging\DebugLogger::warning( 'No rows returned despite positive total' );
		}

		$total_pages = ceil( $total_logs / $rows_per_page );

		?>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Timestamp', 'members-for-kofi' ); ?></th>
					<th><?php esc_html_e( 'User ID', 'members-for-kofi' ); ?></th>
					<th><?php esc_html_e( 'Email', 'members-for-kofi' ); ?></th>
					<th><?php esc_html_e( 'Action', 'members-for-kofi' ); ?></th>
					<th><?php esc_html_e( 'Role', 'members-for-kofi' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No logs available.', 'members-for-kofi' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log['timestamp'] ); ?></td>
							<td><?php echo esc_html( $log['user_id'] ); ?></td>
							<td><?php echo esc_html( $log['email'] ); ?></td>
							<td><?php echo esc_html( $log['action'] ); ?></td>
							<td><?php echo esc_html( $log['role'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<div style="display: flex; align-items: center; justify-content: space-between; margin-top: 15px;">
			<input type="hidden" id="kofi-members-current-search" value="<?php echo esc_attr( $search ); ?>" />
			<!-- Clear Logs Button -->
			<form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'user_logs', admin_url( 'admin.php?page=members-for-kofi' ) ) ); ?>" style="margin-right: 15px;">
				<?php wp_nonce_field( 'clear_user_logs_action', 'clear_user_logs_nonce' ); ?>
				<button type="button" name="clear_logs" class="button button-secondary">
					<?php esc_html_e( 'Clear Logs', 'members-for-kofi' ); ?>
				</button>
			</form>

			<!-- Rows Per Page Dropdown -->
			<form method="get" style="margin-right: 15px;">
				<input type="hidden" name="page" value="members-for-kofi">
				<input type="hidden" name="tab" value="user_logs">
				<label for="rows_per_page" style="margin-right: 10px;"><?php esc_html_e( 'Rows per page:', 'members-for-kofi' ); ?></label>
				<select name="rows_per_page" id="rows_per_page" style="width: auto;">
					<?php foreach ( array( 10, 25, 50, 100 ) as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $rows_per_page, $option ); ?>>
							<?php echo esc_html( $option ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>
			<div class="pagination-links" style="display:flex; gap:4px; flex-wrap:wrap;">
				<?php if ( $total_pages > 1 ) : ?>
					<?php
					$prev_page = max( 1, $current_page - 1 );
					$next_page = min( $total_pages, $current_page + 1 );
					if ( $current_page > 1 ) {
						echo '<button type="button" class="button members-for-kofi-page-btn" data-page="' . esc_attr( $prev_page ) . '">&laquo; ' . esc_html__( 'Previous', 'members-for-kofi' ) . '</button>';
					}
					for ( $p = 1; $p <= $total_pages; $p++ ) {
						$cls = 'button members-for-kofi-page-btn' . ( $p === $current_page ? ' button-primary' : '' );
						echo '<button type="button" class="' . esc_attr( $cls ) . '" data-page="' . esc_attr( $p ) . '">' . esc_html( (string) $p ) . '</button>';
					}
					if ( $current_page < $total_pages ) {
						echo '<button type="button" class="button members-for-kofi-page-btn" data-page="' . esc_attr( $next_page ) . '">' . esc_html__( 'Next', 'members-for-kofi' ) . ' &raquo;</button>';
					}
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}