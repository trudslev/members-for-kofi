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

namespace MembersForKofi\Tests\Privacy;

use MembersForKofi\Logging\RequestLogger;
use MembersForKofi\Logging\UserLogger;
use MembersForKofi\Privacy\PersonalData;
use MembersForKofi\Tests\TestCase;

/**
 * Covers the personal data exporter and eraser for the plugin's logs.
 */
class PersonalDataTest extends TestCase {

	/**
	 * Writes log rows for an email.
	 *
	 * @param string $email         Email.
	 * @param int    $user_rows     User-log rows.
	 * @param int    $request_rows  Request-log rows.
	 * @return void
	 */
	private function seed( string $email, int $user_rows, int $request_rows ): void {
		$users    = new UserLogger();
		$requests = new RequestLogger();

		for ( $i = 0; $i < $user_rows; $i++ ) {
			$users->log_donation( 1, $email, 5.0, 'USD' );
		}
		for ( $i = 0; $i < $request_rows; $i++ ) {
			$requests->log_request( array( 'email' => $email ), 200, true );
		}
	}

	/**
	 * Runs the exporter to completion.
	 *
	 * @param string $email Email.
	 * @return array Exported items.
	 */
	private function export_all( string $email ): array {
		$items = array();

		for ( $page = 1; $page < 50; $page++ ) {
			$result = PersonalData::export( $email, $page );
			$items  = array_merge( $items, $result['data'] );
			if ( $result['done'] ) {
				return $items;
			}
		}

		$this->fail( 'The exporter never reported done.' );
	}

	/**
	 * Both tables are exported across pages, and only the requested
	 * person's rows.
	 */
	public function test_both_logs_are_exported_across_pages(): void {
		$this->seed( 'exported@example.com', 150, 3 );
		$this->seed( 'other@example.com', 2, 2 );

		$items  = $this->export_all( 'exported@example.com' );
		$groups = array_count_values( array_column( $items, 'group_id' ) );

		$this->assertSame( 150, $groups['members-for-kofi-user-log'] ?? 0 );
		$this->assertSame( 3, $groups['members-for-kofi-request-log'] ?? 0 );
	}

	/**
	 * A person with only request-log rows is exported too.
	 */
	public function test_request_rows_alone_are_exported(): void {
		$this->seed( 'requests.only@example.com', 0, 2 );

		$this->assertCount( 2, $this->export_all( 'requests.only@example.com' ) );
	}

	/**
	 * Erasure removes that person's rows from both tables and nobody else's.
	 */
	public function test_erasure_removes_only_that_persons_rows(): void {
		$this->seed( 'erased@example.com', 2, 2 );
		$this->seed( 'kept@example.com', 1, 1 );

		$result = PersonalData::erase( 'erased@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array(), $this->export_all( 'erased@example.com' ) );
		$this->assertCount( 2, $this->export_all( 'kept@example.com' ) );
	}

	/**
	 * Both tools are registered with WordPress.
	 */
	public function test_the_exporter_and_eraser_are_registered(): void {
		$this->assertArrayHasKey( 'members-for-kofi', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'members-for-kofi', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}
}
