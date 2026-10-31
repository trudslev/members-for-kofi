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

namespace MembersForKofi\Tests;

/**
 * Keeps the bundled translations complete.
 *
 * A bundled translation is shipped to every site, so a partly translated
 * one shows a mix of languages. Each must cover every string in the
 * template; regenerate the template with `make pot`, translate, `make mo`.
 */
class TranslationTest extends TestCase {

	/**
	 * Reads the msgid => msgstr pairs of a .po or .pot file.
	 *
	 * @param string $file Path.
	 * @return array<string,string>
	 */
	private static function entries( string $file ): array {
		$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		preg_match_all( '/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m', $text, $matches, PREG_SET_ORDER );

		$entries = array();
		foreach ( $matches as $match ) {
			if ( '' !== $match[1] ) {
				$entries[ $match[1] ] = $match[2];
			}
		}
		return $entries;
	}

	/**
	 * Every bundled translation translates every string in the template.
	 */
	public function test_bundled_translations_are_complete(): void {
		$languages = dirname( __DIR__ ) . '/languages';
		$template  = self::entries( $languages . '/members-for-kofi.pot' );
		$files     = glob( $languages . '/members-for-kofi-*.po' );

		$this->assertNotEmpty( $template );
		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$translated = array_filter( self::entries( $file ), 'strlen' );
			$missing    = array_diff_key( $template, $translated );
			$stale      = array_diff_key( $translated, $template );

			$this->assertSame( array(), array_keys( $missing ), basename( $file ) . ' is missing translations.' );
			$this->assertSame( array(), array_keys( $stale ), basename( $file ) . ' has strings no longer in the template.' );
		}
	}

	/**
	 * Placeholders survive translation, or sprintf() breaks the string.
	 */
	public function test_translations_keep_their_placeholders(): void {
		foreach ( glob( dirname( __DIR__ ) . '/languages/members-for-kofi-*.po' ) as $file ) {
			foreach ( self::entries( $file ) as $source => $translation ) {
				preg_match_all( '/%(?:\d+\$)?[sd]/', $source, $expected );
				preg_match_all( '/%(?:\d+\$)?[sd]/', $translation, $actual );
				sort( $expected[0] );
				sort( $actual[0] );
				$this->assertSame( $expected[0], $actual[0], basename( $file ) . ': ' . $source );
			}
		}
	}
}
