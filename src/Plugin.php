<?php
/**
 * This file is part of the Ko-fi Members plugin.
 *
 * PHP version 7.4+
 *
 * Ko-fi Members is free software: you can redistribute it and/or modify
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
 * @category WordPress_Plugin
 * @package  KofiMembers
 * @author   Sune Trudslev <sune@trudslev.net>
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link     https://github.com/trudslev/kofi-members
 */

namespace KofiMembers;

use KofiMembers\Admin\AdminSettings;
use KofiMembers\Logging\LoggerFactory;
use KofiMembers\Cron\RoleExpiryChecker;
use KofiMembers\Webhook\Webhook;
use KofiMembers\Logging\UserLogger;

/**
 * Main plugin class for Ko-fi Members.
 *
 * Handles initialization, activation, deactivation, and uninstall routines,
 * as well as admin menu, settings, logger, cron, and webhook integration.
 *
 * @category WordPress_Plugin
 * @package  KofiMembers
 * @author   Sune Trudslev <sune@trudslev.net>
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link     https://github.com/trudslev/kofi-members
 */
class Plugin {
	/**
	 * Constructor to initialize the plugin.
	 *
	 * Sets up actions for admin menu, settings registration, logger initialization,
	 * cron job scheduling, rewrite rules, and query variable initialization.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'init', array( $this, 'initialize_logger' ) );
		add_action( 'init', array( $this, 'initialize_cron' ) );
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_action(
			'template_redirect',
			function () {
				if ( intval( get_query_var( 'kofi_webhook' ) ) === 1 ) {
					$webhook  = new Webhook();
					$response = $webhook->handle();

					// Send HTTP status.
					status_header( $response->get_status() );

					// Send headers.
					foreach ( $response->get_headers() as $key => $value ) {
						header( "{$key}: {$value}" );
					}

					// Output the response data as JSON.
					echo wp_json_encode( $response->get_data() );
					exit;
				}
			}
		);
		add_filter( 'query_vars', array( $this, 'initialize_query_vars' ) );
	}

	/**
	 * Activation hook for the plugin.
	 *
	 * Initializes default plugin options, adds rewrite rules, flushes them,
	 * and creates the user logs table.
	 *
	 * @return void
	 */
	public static function activate(): void {

		// Initialize default plugin options.
		if ( false === get_option( 'kofi_members_options' ) ) {
			add_option(
				'kofi_members_options',
				array(
					'verification_token' => '',
					'only_subscriptions' => true,
					'tier_role_map'      => array(),
					'default_role'       => '',
					'enable_expiry'      => true,
					'role_expiry_days'   => 35,
					'log_enabled'        => false,
					'log_level'          => 'info',
				)
			);
		}

		// Add rewrite rules and flush them.
		self::add_rewrite_rules();
		flush_rewrite_rules();

		// Create the user logs table.
		UserLogger::create_table();
	}

	/**
	 * Deactivation hook for the plugin.
	 *
	 * Flushes rewrite rules on plugin deactivation.
	 */
	public static function deactivate(): void {
		// Clear rewrite rules if necessary.
		flush_rewrite_rules();

		// Unschedule the cron job if it exists.
		$timestamp = wp_next_scheduled( 'kofi_members_check_role_expiry' );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'kofi_members_check_role_expiry' );
		}
	}

	/**
	 * Uninstall hook for the plugin.
	 *
	 * Removes plugin options, rewrite rules, and the user logs table on uninstall.
	 */
	public static function uninstall(): void {
		// Remove options.
		delete_option( 'kofi_members_options' );

		// Remove rewrite rules added by this plugin.
		global $wp_rewrite;
		$rules = $wp_rewrite->rules ?? array();

		foreach ( $rules as $pattern => $query ) {
			if ( strpos( $query, 'kofi_members_webhook' ) !== false ) {
				unset( $rules[ $pattern ] );
			}
		}

		flush_rewrite_rules();

		// Remove the user logs table.
		UserLogger::drop_table();
	}

	/**
	 * Adds the Ko-fi Members menu to the WordPress admin dashboard.
	 *
	 * Registers the main menu page for the plugin in the admin interface.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Ko-fi Members', 'kofi-members' ),
			__( 'Ko-fi Members', 'kofi-members' ),
			'manage_options',
			'kofi-members',
			array( $this, 'render_settings_page' ),
			'dashicons-heart',
			80
		);
	}

	/**
	 * Registers plugin settings via the AdminSettings class.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		( new AdminSettings() )->register_settings();
	}

	/**
	 * Renders the plugin settings page in the WordPress admin.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		( new AdminSettings() )->render_settings_page();
	}

	/**
	 * Initializes the logger and logs the plugin initialization.
	 *
	 * @return void
	 */
	public function initialize_logger(): void {
		LoggerFactory::get_logger()->info( 'Ko-fi Members plugin initialized' );
	}

	/**
	 * Initializes the scheduled cron job for role expiry checking.
	 *
	 * @return void
	 */
	public function initialize_cron(): void {
		// Schedule the cron job if it isn't already scheduled.
		if ( ! wp_next_scheduled( 'kofi_members_check_expired_roles' ) ) {
			wp_schedule_event( time(), 'daily', 'kofi_members_check_expired_roles' );
		}

		// Hook the cron job to the RoleExpiryChecker.
		add_action(
			'kofi_members_check_expired_roles',
			function () {
				$user_logger         = new UserLogger();
				$role_expiry_checker = new RoleExpiryChecker( $user_logger );
				$role_expiry_checker->check_and_remove_expired_roles();
			}
		);
	}

	/**
	 * Adds custom rewrite rules for Ko-fi webhook endpoint.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^kofi-webhook/?$', 'index.php?kofi_webhook=1', 'top' );
	}

	/**
	 * Adds custom query variable for Ko-fi webhook endpoint.
	 *
	 * @param array $vars Existing query variables.
	 * @return array Modified query variables with 'kofi_webhook' added.
	 */
	public function initialize_query_vars( $vars ): array {
		$vars[] = 'kofi_webhook';
		return $vars;
	}
}
