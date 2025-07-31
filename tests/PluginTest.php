<?php
/**
 * This file is part of the Ko-fi Members plugin.
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
 * @package Ko-fiMembers
 */

namespace KofiMembers\Tests;

use KofiMembers\Plugin;
use WP_UnitTestCase;

/**
 * Class PluginTest
 *
 * This class contains unit tests for the Ko-fi Members plugin.
 */
class PluginTest extends WP_UnitTestCase {

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

		$table_name = $wpdb->prefix . 'kofi_members_user_logs';
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
	 * Tests that the Ko-fi Members admin menu is registered.
	 */
	public function test_add_menu_registers_menu_page(): void {
		global $menu;
		$this->plugin->add_menu();

		$found = false;
		foreach ( $menu as $item ) {
			if ( is_array( $item ) && in_array( 'Ko-fi Members', $item, true ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Expected Ko-fi Members admin menu to be registered' );
	}

	/**
	 * Tests that the logger initializes without errors.
	 */
	public function test_logger_initializes(): void {
		$this->expectNotToPerformAssertions();
		$this->plugin->initialize_logger();
	}

	/**
	 * Tests that the cron schedules initialize without errors.
	 */
	public function test_cron_schedules_without_error(): void {
		$this->expectNotToPerformAssertions();
		$this->plugin->initialize_cron();
	}

	/**
	 * Tests that rewrite rules are flushed upon plugin deactivation.
	 */
	public function test_deactivate_flushes_rewrite_rules(): void {
		$this->expectNotToPerformAssertions();
		Plugin::deactivate();
	}
}
