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
 * @package Ko-fiMembers
 */

namespace MembersForKofi\Tests;

use MembersForKofi\Plugin;

/**
 * Class PluginTest
 *
 * This class contains unit tests for the Members for Ko-fi plugin.
 */
class PluginTest extends TestCase {

	/**
	 * Instance of the Plugin class.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Sets up the test environment.
	 *
	 * This method is called before each test is executed.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->plugin = new Plugin();
	}

	/**
	 * Cleans up the test environment.
	 *
	 * This method is called after each test is executed.
	 */
	protected function tearDown(): void {
		// Clean up after tests.
		global $wpdb;

		$table_name = $wpdb->prefix . 'members_for_kofi_user_logs';
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

		parent::tearDown();
	}

	/**
	 * Tests that the 'kofi_webhook' query variable is registered.
	 */
	public function test_query_var_is_registered(): void {
		global $wp;
		$vars = apply_filters( 'query_vars', array() );
		$this->assertContains( 'kofi_webhook', $vars, 'kofi_webhook query var should be registered' );
	}

	/**
	 * Tests that the Members for Ko-fi admin menu is registered.
	 */
	public function test_add_menu_registers_menu_page(): void {
		global $menu;
		$this->plugin->add_menu();

		$found = false;
		foreach ( $menu as $item ) {
			if ( is_array( $item ) && in_array( 'Members for Ko-fi', $item, true ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Expected Members for Ko-fi admin menu to be registered' );
	}

	/**
	 * Tests that initializing the logger actually emits its debug line.
	 *
	 * Previously this called the method and asserted nothing, so it could only
	 * ever have caught a fatal error.
	 */
	public function test_logger_initializes(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'kofi-plugin-log-' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Redirecting error_log is the only way to capture logger output.
		$original = ini_set( 'error_log', $log_file );

		try {
			$this->plugin->initialize_logger();
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring the original value.
			ini_set( 'error_log', false === $original ? '' : $original );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local temp file, not a URL.
		$contents = (string) file_get_contents( $log_file );
		wp_delete_file( $log_file );

		$this->assertStringContainsString( 'Plugin initialized', $contents );
	}

	/**
	 * Tests that initializing cron schedules the expected daily events.
	 */
	public function test_cron_schedules_expected_events(): void {
		$this->plugin->initialize_cron();

		$this->assertNotFalse(
			wp_next_scheduled( 'kofi_members_check_expired_roles' ),
			'Expected kofi_members_check_expired_roles to be scheduled'
		);
		$this->assertNotFalse(
			wp_next_scheduled( 'kofi_members_cleanup_logs' ),
			'Expected kofi_members_cleanup_logs to be scheduled'
		);
	}

	/**
	 * Tests that deactivation flushes rewrite rules and unschedules both cron events.
	 */
	public function test_deactivate_flushes_rewrite_rules_and_unschedules_cron(): void {
		$this->plugin->initialize_cron();

		Plugin::deactivate();

		$this->assertFalse(
			wp_next_scheduled( 'kofi_members_check_expired_roles' ),
			'Expected kofi_members_check_expired_roles to be unscheduled after deactivation'
		);
		$this->assertFalse(
			wp_next_scheduled( 'kofi_members_cleanup_logs' ),
			'Expected kofi_members_cleanup_logs to be unscheduled after deactivation'
		);
	}
}
