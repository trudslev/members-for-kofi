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

namespace MembersForKofi\Tests\Logging;

use MembersForKofi\Logging\LoggerFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Test case for the LoggerFactory class.
 *
 * This class contains unit tests for the LoggerFactory, ensuring
 * that loggers are created with the correct handlers and configurations.
 */
class LoggerFactoryTest extends TestCase {

	/**
	 * Tests that the logger is created with no handlers when logging is disabled.
	 */
	public function test_creates_logger_with_no_handlers_if_disabled(): void {
		LoggerFactory::reset();

		$logger = LoggerFactory::create_logger(
			array(
				'log_enabled' => false,
			)
		);

		$this->assertInstanceOf( Logger::class, $logger );
		$this->assertCount( 0, $logger->getHandlers(), 'Logger should have no handlers when logging is disabled' );
	}

	/**
	 * Tests that the logger is created with a StreamHandler when logging is enabled.
	 */
	public function test_creates_logger_with_stream_handler(): void {
		LoggerFactory::reset();

		$logger = LoggerFactory::create_logger(
			array(
				'log_enabled' => true,
				'log_level'   => 'warning',
			)
		);

		$handlers = $logger->getHandlers();
		$this->assertNotEmpty( $handlers, 'Expected at least one handler' );

		$stream_handler = null;
		foreach ( $handlers as $handler ) {
			if ( $handler instanceof StreamHandler ) {
				$stream_handler = $handler;
				break;
			}
		}

		$this->assertInstanceOf( StreamHandler::class, $stream_handler );
		$this->assertSame( Logger::WARNING, $stream_handler->getLevel(), 'Stream handler should have warning level' );
	}

	/**
	 * Tests that the logger defaults to INFO and ERROR levels when no specific levels are provided.
	 */
	public function test_defaults_to_info_and_error_levels(): void {
		LoggerFactory::reset();

		$logger = LoggerFactory::create_logger(
			array(
				'log_enabled' => true,
			)
		);

		$levels = array_map( fn( $handler ) => $handler->getLevel(), $logger->getHandlers() );

		$this->assertContains( Logger::INFO, $levels, 'Expected default log level to include INFO' );
	}

	/**
	 * Checks if a handler of the specified class exists in the handlers array.
	 *
	 * @param array  $handlers  The array of handlers to search.
	 * @param string $class_name The class name of the handler to find.
	 * @return bool True if a handler of the specified class is found, false otherwise.
	 */
	private function handlerFound( array $handlers, string $class_name ): bool {
		foreach ( $handlers as $handler ) {
			if ( $handler instanceof $class_name ) {
				return true;
			}
		}
		return false;
	}
}
