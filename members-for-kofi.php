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

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name:       Members for Ko-fi
 * Plugin URI:        https://github.com/trudslev/members-for-kofi
 * Description:       Integrate with Ko-fi to manage WordPress users or roles via webhook.
 * Version:           1.0.0
 * Author:            Sune Adelowo Trudslev
 * Author URI:        https://foodgeek.io
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       members-for-kofi
 * Domain Path:       /languages
 */

// Define plugin constants.
define( 'MEMBERS_FOR_KOFI_PLUGIN_FILE', __FILE__ );
define( 'MEMBERS_FOR_KOFI_PLUGIN_DIR', __DIR__ );
define( 'MEMBERS_FOR_KOFI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader.
require_once MEMBERS_FOR_KOFI_PLUGIN_DIR . '/vendor/autoload.php';

use MembersForKofi\Plugin;

// Register activation and deactivation hooks.
register_activation_hook( MEMBERS_FOR_KOFI_PLUGIN_FILE, array( Plugin::class, 'activate' ) );
register_deactivation_hook( MEMBERS_FOR_KOFI_PLUGIN_FILE, array( Plugin::class, 'deactivate' ) );
register_uninstall_hook( MEMBERS_FOR_KOFI_PLUGIN_FILE, array( Plugin::class, 'uninstall' ) );

// Boot the plugin after all plugins are loaded.
add_action(
	'plugins_loaded',
	function () {
		new Plugin();
	}
);
