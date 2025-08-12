<?php
/**
 * Lightweight debug logger used only when WP_DEBUG is enabled.
 *
 * @package MembersForKofi\Logging
 */

namespace MembersForKofi\Logging;

/**
 * Simple static debug logger wrapping error_log, active only when WP_DEBUG is true.
 */
class DebugLogger {
	/**
	 * Logs a message with optional context when WP_DEBUG is true.
	 *
	 * @param string $level   Log level string (info, warning, error, debug...).
	 * @param string $message Message to log.
	 * @param array  $context Optional context array.
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		$force_debug = defined( 'MEMBERS_FOR_KOFI_FORCE_DEBUG' ) && MEMBERS_FOR_KOFI_FORCE_DEBUG;
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || $force_debug ) {
			$prefix = '[Members for Ko-fi][' . strtoupper( $level ) . '] ';
			$line   = $prefix . $message;
			if ( ! empty( $context ) ) {
				$line .= ' ' . wp_json_encode( $context );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $line );
		}
	}

	/**
	 * Info level.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Warning level.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Error level.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Debug level.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}
}
