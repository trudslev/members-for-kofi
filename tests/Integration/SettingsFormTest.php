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

namespace MembersForKofi\Tests\Integration;

// Runs on the host, outside WordPress: it logs in and submits the real
// settings form over HTTP, then reads the stored option back with WP-CLI.
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink

/**
 * Submits the settings form the way a browser does.
 *
 * A browser leaves unchecked boxes out of the submission altogether, which no
 * test that hands sanitize_options() an array reproduces. That gap hid that
 * "Automatically Clear Logs" could never be switched off.
 *
 * @group integration
 */
class SettingsFormTest extends IntegrationTestCase {

	/**
	 * The plugin option as it was before the test, as JSON.
	 *
	 * @var string
	 */
	private string $saved_options = '';

	/**
	 * Cookie jar of the administrator session.
	 *
	 * @var string
	 */
	private string $jar = '';

	/**
	 * Remembers the option so the shared test site is left as found.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved_options = self::wp( 'option get members_for_kofi_options --format=json' );
		$this->assertNotSame( '', $this->saved_options, 'The test site should have the plugin configured.' );
	}

	/**
	 * Restores the option and removes the cookie jar.
	 */
	protected function tearDown(): void {
		self::wp( 'option update members_for_kofi_options ' . escapeshellarg( $this->saved_options ) . ' --format=json' );

		if ( '' !== $this->jar && file_exists( $this->jar ) ) {
			unlink( $this->jar );
		}

		parent::tearDown();
	}

	/**
	 * Unticking "Automatically Clear Logs" and saving turns it off.
	 */
	public function test_unticking_auto_clear_logs_turns_it_off(): void {
		self::wp( 'option patch update members_for_kofi_options auto_clear_logs 1 --format=json' );

		$ticked = $this->settings_form( array(), array() );
		$this->assertStringContainsString( rawurlencode( 'members_for_kofi_options[auto_clear_logs]' ) . '=1', $ticked, 'The box is ticked to begin with.' );

		$this->submit( $this->settings_form( array(), array( 'members_for_kofi_options[auto_clear_logs]' ) ) );

		$this->assertFalse( $this->stored()['auto_clear_logs'], 'Unticking the box and saving must store "off".' );
	}

	/**
	 * Ticking it again turns it back on.
	 */
	public function test_ticking_auto_clear_logs_turns_it_on(): void {
		self::wp( 'option patch update members_for_kofi_options auto_clear_logs false --format=json' );

		$this->submit( $this->settings_form( array( 'members_for_kofi_options[auto_clear_logs]' => '1' ), array() ) );

		$this->assertTrue( $this->stored()['auto_clear_logs'] );
	}

	/**
	 * The stored plugin option.
	 *
	 * @return array
	 */
	private function stored(): array {
		return (array) json_decode( self::wp( 'option get members_for_kofi_options --format=json' ), true );
	}

	/**
	 * Logs the administrator in once per test.
	 *
	 * @return string Cookie jar path.
	 */
	private function admin_jar(): string {
		if ( '' !== $this->jar ) {
			return $this->jar;
		}

		$this->jar = (string) tempnam( sys_get_temp_dir(), 'kofi-form-' );

		$curl = curl_init( $this->base_url . '/wp-login.php' );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => http_build_query(
					array(
						'log'         => 'admin',
						'pwd'         => 'admin',
						'wp-submit'   => 'Log In',
						'redirect_to' => $this->base_url . '/wp-admin/',
					)
				),
				CURLOPT_COOKIEJAR      => $this->jar,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		curl_exec( $curl );
		$this->assertSame( '', curl_error( $curl ) );
		unset( $curl ); // cURL writes the jar when the handle goes.

		$this->assertStringContainsString( 'wordpress_logged_in', (string) file_get_contents( $this->jar ) );

		return $this->jar;
	}

	/**
	 * Reads the settings form and serialises it as a browser would.
	 *
	 * Text, hidden, number and password inputs are sent with their value;
	 * checkboxes and radios only when checked; a select sends its selected
	 * option, else its first.
	 *
	 * Fields are kept in document order and sent as a raw query string, so a
	 * hidden input and the checkbox after it both arrive, as from a browser.
	 *
	 * @param array<string,string> $tick   Checkbox names to send as ticked, with their value.
	 * @param array<int,string>    $untick Checkbox names to leave out, as when unticked.
	 * @return string URL-encoded form body.
	 */
	private function settings_form( array $tick, array $untick ): string {
		$curl = curl_init( $this->base_url . '/wp-admin/admin.php?page=members-for-kofi' );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_COOKIEFILE     => $this->admin_jar(),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		$html = (string) curl_exec( $curl );
		$this->assertSame( 200, (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE ) );

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();

		$form = $dom->getElementById( 'members-for-kofi-settings-form' );
		$this->assertNotNull( $form, 'The settings form should be on the page.' );

		$pairs = array();
		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			$name = $input->getAttribute( 'name' );
			if ( '' === $name ) {
				continue;
			}
			$type = strtolower( $input->getAttribute( 'type' ) );
			if ( in_array( $type, array( 'checkbox', 'radio' ), true ) ) {
				if ( in_array( $name, $untick, true ) ) {
					continue;
				}
				if ( isset( $tick[ $name ] ) ) {
					$pairs[] = array( $name, $tick[ $name ] );
				} elseif ( $input->hasAttribute( 'checked' ) ) {
					$pairs[] = array( $name, '' !== $input->getAttribute( 'value' ) ? $input->getAttribute( 'value' ) : 'on' );
				}
				continue;
			}
			if ( in_array( $type, array( 'submit', 'button' ), true ) ) {
				continue;
			}
			$pairs[] = array( $name, $input->getAttribute( 'value' ) );
		}
		foreach ( $form->getElementsByTagName( 'select' ) as $select ) {
			$chosen = null;
			foreach ( $select->getElementsByTagName( 'option' ) as $option ) {
				if ( null === $chosen || $option->hasAttribute( 'selected' ) ) {
					$chosen = $option->getAttribute( 'value' );
					if ( $option->hasAttribute( 'selected' ) ) {
						break;
					}
				}
			}
			if ( null !== $chosen ) {
				$pairs[] = array( $select->getAttribute( 'name' ), $chosen );
			}
		}

		return implode( '&', array_map( static fn( $p ) => rawurlencode( $p[0] ) . '=' . rawurlencode( $p[1] ), $pairs ) );
	}

	/**
	 * Posts the form to options.php, as the Save button does.
	 *
	 * @param string $body URL-encoded form body.
	 * @return void
	 */
	private function submit( string $body ): void {
		$curl = curl_init( $this->base_url . '/wp-admin/options.php' );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_COOKIEFILE     => $this->admin_jar(),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		curl_exec( $curl );

		$this->assertSame( 302, (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE ), 'options.php redirects back after a save.' );
	}
}
