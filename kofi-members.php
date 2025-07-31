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
 * @package KofiMembers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name:       Ko-fi Members
 * Plugin URI:        https://github.com/trudslev/kofi-members
 * Description:       Integrate with Ko-fi to manage WordPress users or roles via webhook.
 * Version:           1.0.0
 * Author:            Sune Adelowo Trudslev
 * Author URI:        https://foodgeek.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kofi-members
 * Domain Path:       /languages
 */

// Define plugin constants.
define( 'KOFIMEMBERS_PLUGIN_FILE', __FILE__ );
define( 'KOFIMEMBERS_PLUGIN_DIR', __DIR__ );
define( 'KOFIMEMBERS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader.
require_once KOFIMEMBERS_PLUGIN_DIR . '/vendor/autoload.php';

use KofiMembers\Core\Activator;
use KofiMembers\Core\Deactivator;
use KofiMembers\Plugin;

// Register activation and deactivation hooks.
register_activation_hook( KOFIMEMBERS_PLUGIN_FILE, array( Plugin::class, 'activate' ) );
register_deactivation_hook( KOFIMEMBERS_PLUGIN_FILE, array( Plugin::class, 'deactivate' ) );
register_uninstall_hook( KOFIMEMBERS_PLUGIN_FILE, array( Plugin::class, 'uninstall' ) );

// Boot the plugin after all plugins are loaded.
add_action(
	'plugins_loaded',
	function () {
		new Plugin();
	}
);
