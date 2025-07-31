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

namespace KofiMembers\Logging;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Factory class for creating and managing a logger instance.
 *
 * This class provides methods to create a logger with specific settings,
 * reset the logger instance, and retrieve the logger instance.
 *
 * @package KofiMembers\Logging
 */
class LoggerFactory {

	/**
	 * Holds the singleton instance of the Logger.
	 *
	 * @var Logger|null
	 */
	private static ?Logger $instance = null;

	/**
	 * Private constructor to prevent direct instantiation of the class.
	 */
	private function __construct() {} // Prevent direct instantiation

	/**
	 * Retrieves the singleton instance of the Logger.
	 *
	 * @return Logger The logger instance.
	 */
	public static function get_logger(): Logger {
		if ( null === self::$instance ) {
			$settings       = get_option( 'kofi_members_options', array() );
			self::$instance = self::create_logger( $settings );
		}

		return self::$instance;
	}

	/**
	 * Resets the singleton instance of the Logger.
	 *
	 * This method sets the logger instance to null, allowing it to be reinitialized.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Creates a logger instance with the specified settings.
	 *
	 * @param array $settings The settings for configuring the logger.
	 * @return Logger The configured logger instance.
	 */
	public static function create_logger( array $settings ): Logger {
		$logger = new Logger( 'kofi-members' );

		$log_level = Logger::toMonologLevel( $settings['log_level'] ?? 'info' );

		// File logging.
		if ( ! empty( $settings['log_enabled'] ) ) {
			$log_dir = WP_CONTENT_DIR . '/logs';

			// Initialize the WordPress filesystem.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			// Check if the directory exists, and create it if it doesn't.
			if ( ! $wp_filesystem->is_dir( $log_dir ) ) {
				$wp_filesystem->mkdir( $log_dir, FS_CHMOD_DIR );
			}

			$log_path       = $log_dir . '/kofi-members.log';
			$stream_handler = new StreamHandler( $log_path, $log_level );
			$line_format    = "[%datetime%] %level_name%: %message%\n%context%\n";
			$formatter      = new LineFormatter( $line_format, 'Y-m-d H:i:s', true, true );
			$formatter->includeStacktraces( true );
			$stream_handler->setFormatter( $formatter );

			$logger->pushHandler( $stream_handler );
		}

		return $logger;
	}
}
