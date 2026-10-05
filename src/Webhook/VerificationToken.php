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

namespace MembersForKofi\Webhook;

defined( 'ABSPATH' ) || exit;

use MembersForKofi\Logging\DebugLogger;

/**
 * Owns how the Ko-fi verification token is stored and checked.
 *
 * Only a SHA-256 hash of the token is kept, so a database dump, a backup or a
 * stray `wp option get` no longer reveals it. The token is a UUID, so brute
 * force is not the threat and a slow KDF would only cost time on every webhook;
 * it is deliberately not salted with wp_salt() either, because rotating the
 * site's salts would then silently break every webhook.
 *
 * Releases before 1.2.0 stored the token in plaintext. migrate() moves a site
 * over, and is the only code allowed to remove a plaintext token.
 */
class VerificationToken {

	/**
	 * Option holding the plugin settings, token included.
	 *
	 * @var string
	 */
	public const OPTION = 'members_for_kofi_options';

	/**
	 * Key the hashed token is stored under.
	 *
	 * @var string
	 */
	public const HASH_KEY = 'verification_token_sha256';

	/**
	 * Key the plaintext token was stored under before 1.2.0.
	 *
	 * @var string
	 */
	public const LEGACY_KEY = 'verification_token';

	/**
	 * Hashes a token for storage or comparison.
	 *
	 * @param string $token Token as Ko-fi sends it.
	 * @return string Lowercase hex SHA-256.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * The hash stored on this site, without touching the database.
	 *
	 * A plaintext token left over from before 1.2.0 counts as configured: its
	 * hash is what migrate() would store.
	 *
	 * @param mixed $options The plugin options.
	 * @return string The hash, or '' when no token is configured.
	 */
	public static function stored_hash( $options ): string {
		if ( ! is_array( $options ) ) {
			return '';
		}

		if ( self::is_valid_hash( $options[ self::HASH_KEY ] ?? null ) ) {
			return $options[ self::HASH_KEY ];
		}

		$legacy = $options[ self::LEGACY_KEY ] ?? null;

		return is_string( $legacy ) && '' !== $legacy ? self::hash( $legacy ) : '';
	}

	/**
	 * The hash an incoming token must match.
	 *
	 * Fallback, to be removed in 1.4.0: when a site still holds only a plaintext
	 * token -- restored from an old backup, written by another code path, or
	 * reached before the upgrade on init ran -- it is verified against that
	 * plaintext and migrated on the spot. Once this is gone, tests that seed a
	 * plaintext `verification_token` through update_option() must switch to the
	 * hash key.
	 *
	 * @param mixed $options The plugin options.
	 * @return string The hash, or '' when no token is configured.
	 */
	public static function expected_hash( $options ): string {
		$hash = self::stored_hash( $options );

		if ( '' !== $hash && ! self::is_valid_hash( $options[ self::HASH_KEY ] ?? null ) ) {
			self::migrate();
		}

		return $hash;
	}

	/**
	 * Explains why a request that carried a token was not authenticated.
	 *
	 * @param mixed $options The plugin options.
	 * @return string 'not_configured' or 'mismatch'.
	 */
	public static function failure_reason( $options ): string {
		return '' === self::stored_hash( $options ) ? 'not_configured' : 'mismatch';
	}

	/**
	 * Reports whether a hash and a different plaintext token are both stored.
	 *
	 * Webhooks are checked against the hash, but nobody can inspect it, so an
	 * administrator has to be told and asked to re-enter the token.
	 *
	 * @param mixed $options The plugin options.
	 * @return bool
	 */
	public static function has_conflict( $options ): bool {
		if ( ! is_array( $options ) || ! self::is_valid_hash( $options[ self::HASH_KEY ] ?? null ) ) {
			return false;
		}

		$legacy = $options[ self::LEGACY_KEY ] ?? null;

		return is_string( $legacy ) && '' !== $legacy
			&& ! hash_equals( $options[ self::HASH_KEY ], self::hash( $legacy ) );
	}

	/**
	 * A short, non-secret fingerprint of the stored token.
	 *
	 * Eight hex characters of a SHA-256 reveal nothing usable, and they are the
	 * only way left to confirm two sites hold the same token.
	 *
	 * @param mixed $options The plugin options.
	 * @return string Eight hex characters, or '' when no token is configured.
	 */
	public static function fingerprint( $options ): string {
		return substr( self::stored_hash( $options ), 0, 8 );
	}

	/**
	 * Replaces a stored plaintext token with its hash.
	 *
	 * Idempotent and safe to run from concurrent requests: the option is
	 * re-read from the database right before writing, a hash is only ever
	 * computed from the plaintext key (so never from a hash), and the plaintext
	 * is removed only in the same write that stores a non-empty hash. Anything
	 * it cannot interpret is left exactly as it was.
	 *
	 * @return bool True when the options were changed.
	 */
	public static function migrate(): bool {
		// Another request may have migrated, or an admin saved a new token, since
		// this one loaded its options.
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$options = get_option( self::OPTION );

		if ( ! is_array( $options ) || ! array_key_exists( self::LEGACY_KEY, $options ) ) {
			return false;
		}

		$legacy = $options[ self::LEGACY_KEY ];

		if ( ! is_string( $legacy ) || '' === $legacy ) {
			return false;
		}

		$hash = self::hash( $legacy );

		if ( self::is_valid_hash( $options[ self::HASH_KEY ] ?? null ) ) {
			if ( ! hash_equals( $options[ self::HASH_KEY ], $hash ) ) {
				// Two different tokens, and no way to tell which one Ko-fi sends.
				// Keep both; has_conflict() surfaces this in wp-admin.
				DebugLogger::warning( 'Stored verification token hash and plaintext token differ; leaving both in place' );
				return false;
			}

			// The plaintext is a redundant copy of what is already hashed.
			unset( $options[ self::LEGACY_KEY ] );
			return update_option( self::OPTION, $options );
		}

		$options[ self::HASH_KEY ] = $hash;
		unset( $options[ self::LEGACY_KEY ] );

		$updated = update_option( self::OPTION, $options );

		if ( $updated ) {
			DebugLogger::info( 'Verification token migrated to a hash' );
		}

		return $updated;
	}

	/**
	 * Reports whether a value looks like a hash this class wrote.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	private static function is_valid_hash( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $value );
	}
}
