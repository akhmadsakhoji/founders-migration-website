<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests;

/**
 * Base test case with a throw-away working directory and system tool helpers.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/**
	 * Per-test temporary directory.
	 *
	 * @var string
	 */
	protected $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = sys_get_temp_dir() . '/fmw-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp, 0700, true );
	}

	protected function tearDown(): void {
		$this->remove( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Writes a file (creating parents) and returns its path.
	 *
	 * @param string $relative Path under the temp dir.
	 * @param string $contents Contents.
	 * @return string
	 */
	protected function make_file( string $relative, string $contents ): string {
		$path = $this->tmp . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * Absolute path of a system tool, or null when missing.
	 *
	 * @param string $tool Command name.
	 * @return string|null
	 */
	protected function tool( string $tool ): ?string {
		$path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $tool ) . ' 2>/dev/null' ), " \n\r\t\v\0" );
		return '' === $path ? null : $path;
	}

	/**
	 * Runs a shell command and returns [exit code, combined output].
	 *
	 * @param string $command Command line.
	 * @return array{0:int,1:string}
	 */
	protected function run_command( string $command ): array {
		exec( $command . ' 2>&1', $output, $code );
		return array( $code, implode( "\n", $output ) );
	}

	/**
	 * Recursively removes a path without following symlinks.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	protected function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$this->remove( $path . '/' . $item );
			}
		}
		rmdir( $path );
	}
}
