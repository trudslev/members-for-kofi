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

namespace MembersForKofi;

defined( 'ABSPATH' ) || exit;

use MembersForKofi\Logging\DebugLogger;

/**
 * Runs the plugin's public filters and actions so a faulty callback cannot
 * cost a payment.
 *
 * Hooks fire while a Ko-fi payment is being processed. An exception thrown by
 * another plugin's callback used to have nowhere to go but a 500, and Ko-fi
 * retries a 500 until it gives up on the webhook. Here a throwing filter
 * yields its default and a throwing action is skipped; both are written to
 * the debug log by class and location only, since the message may carry
 * whatever the callback was handling.
 *
 * @since 1.6.0
 */
final class Hooks {

	/**
	 * Applies a filter, returning the default when a callback throws.
	 *
	 * @param string $hook    Filter name.
	 * @param mixed  $value   Default value.
	 * @param mixed  ...$args Further arguments for the callbacks.
	 * @return mixed
	 */
	public static function filter( string $hook, $value, ...$args ) {
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Every caller passes a members_for_kofi_ name.
			return apply_filters( $hook, $value, ...$args );
		} catch ( \Throwable $e ) {
			self::report( $hook, $e );
			return $value;
		}
	}

	/**
	 * Fires an action, carrying on when a callback throws.
	 *
	 * @param string $hook    Action name.
	 * @param mixed  ...$args Arguments for the callbacks.
	 * @return void
	 */
	public static function action( string $hook, ...$args ): void {
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Every caller passes a members_for_kofi_ name.
			do_action( $hook, ...$args );
		} catch ( \Throwable $e ) {
			self::report( $hook, $e );
		}
	}

	/**
	 * The payload as hook callbacks see it: without the verification token.
	 *
	 * Callbacks have no use for the secret, and one that logs or forwards the
	 * payload would otherwise leak it.
	 *
	 * @param array $body Webhook payload.
	 * @return array
	 */
	public static function payload( array $body ): array {
		unset( $body['verification_token'] );

		return $body;
	}

	/**
	 * Records a callback failure in the debug log.
	 *
	 * @param string     $hook Hook name.
	 * @param \Throwable $e    What was thrown.
	 * @return void
	 */
	private static function report( string $hook, \Throwable $e ): void {
		DebugLogger::error(
			'A hook callback failed; carrying on without it',
			array(
				'hook'      => $hook,
				'exception' => get_class( $e ),
				'at'        => basename( $e->getFile() ) . ':' . $e->getLine(),
			)
		);
	}
}
