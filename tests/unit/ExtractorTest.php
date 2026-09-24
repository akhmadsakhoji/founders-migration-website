<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests\Unit;

use Founders\Migration\Archive\Extractor;
use Founders\Migration\Archive\PathGuard;
use Founders\Migration\Archive\PlainFileSink;
use Founders\Migration\Archive\TarReader;
use Founders\Migration\Archive\TarWriter;
use Founders\Migration\Archive\UnsafePathException;
use Founders\Migration\Tests\TestCase;

final class ExtractorTest extends TestCase {

	/**
	 * Builds an archive from a callback that receives the writer.
	 *
	 * @param callable $build Receives TarWriter.
	 * @return string Archive path.
	 */
	private function archive( callable $build ): string {
		$path   = $this->tmp . '/' . bin2hex( random_bytes( 4 ) ) . '.tar';
		$writer = new TarWriter( new PlainFileSink( $path ) );
		$build( $writer );
		$writer->finish();
		return $path;
	}

	public function test_extracts_files_directories_and_safe_symlinks(): void {
		$path = $this->archive(
			static function ( TarWriter $w ) {
				$w->add_directory( 'uploads', 0755, 1700000000 );
				$w->add_string( 'uploads/2026/a.txt', 'hello', 0644, 1700000000 );
				$w->add_symlink( 'uploads/current', '2026' );
			}
		);

		$root      = $this->tmp . '/root';
		$extractor = new Extractor( $root );
		$this->assertSame( 3, $extractor->extract_all( TarReader::open( $path ) ) );

		$this->assertSame( 'hello', file_get_contents( $root . '/uploads/2026/a.txt' ) );
		$this->assertSame( 1700000000, filemtime( $root . '/uploads/2026/a.txt' ) );
		$this->assertSame( '2026', readlink( $root . '/uploads/current' ) );
	}

	/**
	 * @dataProvider unsafe_names
	 *
	 * @param string $name Unsafe entry name.
	 */
	public function test_path_traversal_names_are_rejected( string $name ): void {
		$this->expectException( UnsafePathException::class );
		PathGuard::relative( $name );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function unsafe_names(): array {
		return array(
			'parent'        => array( '../evil.php' ),
			'nested parent' => array( 'uploads/../../evil.php' ),
			'absolute'      => array( '/etc/passwd' ),
			'drive letter'  => array( 'C:/Windows/evil.dll' ),
			'backslash'     => array( '..\\evil.php' ),
			'nul byte'      => array( "a.php\0.jpg" ),
		);
	}

	public function test_harmless_names_are_normalised(): void {
		$this->assertSame( 'uploads/a.txt', PathGuard::relative( './uploads//a.txt' ) );
		$this->assertSame( '', PathGuard::relative( './' ) );
	}

	public function test_traversal_entry_does_not_escape_the_root(): void {
		// Hand-craft an archive entry named ../escaped.txt.
		$path = $this->archive(
			static function ( TarWriter $w ) {
				$w->add_string( '../escaped.txt', 'pwned' );
			}
		);

		try {
			( new Extractor( $this->tmp . '/root' ) )->extract_all( TarReader::open( $path ) );
			$this->fail( 'Expected UnsafePathException.' );
		} catch ( UnsafePathException $e ) {
			$this->assertFileDoesNotExist( $this->tmp . '/escaped.txt' );
		}
	}

	public function test_symlink_escaping_the_root_is_rejected(): void {
		foreach ( array( '../../outside', '/etc' ) as $target ) {
			$path = $this->archive(
				static function ( TarWriter $w ) use ( $target ) {
					$w->add_symlink( 'uploads/link', $target );
				}
			);

			try {
				( new Extractor( $this->tmp . '/root-' . md5( $target ) ) )->extract_all( TarReader::open( $path ) );
				$this->fail( 'Expected UnsafePathException for ' . $target );
			} catch ( UnsafePathException $e ) {
				$this->assertFalse( is_link( $this->tmp . '/root-' . md5( $target ) . '/uploads/link' ) );
			}
		}
	}

	public function test_nothing_is_written_through_a_symlinked_directory(): void {
		// "a" points inside the root, but a relative link placed under it could escape.
		$path = $this->archive(
			static function ( TarWriter $w ) {
				$w->add_symlink( 'a', '.' );
				$w->add_string( 'a/b.txt', 'through the link' );
			}
		);

		$this->expectException( UnsafePathException::class );
		( new Extractor( $this->tmp . '/root' ) )->extract_all( TarReader::open( $path ) );
	}

	public function test_existing_symlink_at_target_is_replaced_not_followed(): void {
		$root    = $this->tmp . '/root';
		$outside = $this->make_file( 'outside/secret.txt', 'original' );
		mkdir( $root );
		symlink( $outside, $root . '/file.txt' );

		$path = $this->archive(
			static function ( TarWriter $w ) {
				$w->add_string( 'file.txt', 'restored' );
			}
		);
		( new Extractor( $root ) )->extract_all( TarReader::open( $path ) );

		$this->assertSame( 'original', file_get_contents( $outside ) );
		$this->assertFalse( is_link( $root . '/file.txt' ) );
		$this->assertSame( 'restored', file_get_contents( $root . '/file.txt' ) );
	}
}
