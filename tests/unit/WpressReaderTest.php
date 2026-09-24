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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\WpressReader;
use Founders\Migration\Tests\TestCase;
use Founders\Migration\Tests\WpressBuilder;

require_once dirname( __DIR__ ) . '/WpressBuilder.php';

final class WpressReaderTest extends TestCase {

	/**
	 * Header as All-in-One WP Migration writes it: pack( 'a255a14a12a4096', ... ).
	 *
	 * @param string $name   File name.
	 * @param int    $size   Data size.
	 * @param int    $mtime  Modification time.
	 * @param string $prefix Folder.
	 * @return string
	 */
	public static function header( string $name, int $size, int $mtime, string $prefix ): string {
		return pack( 'a255a14a12a4096', $name, (string) $size, (string) $mtime, $prefix );
	}

	/**
	 * Builds a .wpress file.
	 *
	 * @param array<int,array{0:string,1:string,2?:string}> $files [ name, data, prefix ].
	 * @param bool                                          $end   Whether to write the end block.
	 * @return string
	 */
	private function wpress( array $files, bool $end = true ): string {
		$data = '';
		foreach ( $files as $file ) {
			$data .= self::header( $file[0], strlen( $file[1] ), 1700000000, $file[2] ?? '.' ) . $file[1];
		}
		if ( $end ) {
			$data .= str_repeat( "\0", WpressReader::HEADER_BYTES );
		}
		return $this->make_file( bin2hex( random_bytes( 4 ) ) . '.wpress', $data );
	}

	public function test_reads_names_sizes_and_data(): void {
		$path   = $this->wpress(
			array(
				array( 'package.json', '{"SiteURL":"https://a.test"}' ),
				array( 'a.jpg', str_repeat( 'x', 70000 ), 'uploads/2026/01' ),
				array( 'empty.txt', '', 'themes/t' ),
				array( 'database.sql', 'CREATE TABLE x (a int);' ),
			)
		);
		$reader = WpressReader::open( $path );
		$seen   = array();
		$entry  = $reader->next();
		while ( null !== $entry ) {
			$data = '';
			do {
				$chunk = $reader->read( 4096 );
				$data .= $chunk;
			} while ( '' !== $chunk );
			$seen[ $entry->name ] = array( $entry->size, strlen( $data ), $entry->mtime );
			$entry                = $reader->next();
		}
		$this->assertTrue( $reader->ended() );
		$this->assertSame( filesize( $path ), $reader->position() );
		$reader->close();

		$this->assertSame(
			array(
				'package.json'          => array( 28, 28, 1700000000 ),
				'uploads/2026/01/a.jpg' => array( 70000, 70000, 1700000000 ),
				'themes/t/empty.txt'    => array( 0, 0, 1700000000 ),
				'database.sql'          => array( 23, 23, 1700000000 ),
			),
			$seen
		);
	}

	public function test_unread_data_is_skipped_and_reading_resumes_from_position(): void {
		$path   = $this->wpress(
			array(
				array( 'one', 'first' ),
				array( 'two', 'second' ),
				array( 'three', 'third' ),
			)
		);
		$reader = WpressReader::open( $path );
		$this->assertSame( 'one', $reader->next()->name );
		$this->assertSame( 'fi', $reader->read( 2 ) );
		$this->assertSame( 'two', $reader->next()->name ); // Rest of "first" skipped.
		$reader->skip_bytes( 3 );
		$this->assertSame( 'ond', $reader->read( 100 ) );
		$resume = $reader->position();
		$reader->close();

		$reader = WpressReader::open( $path, $resume );
		$entry  = $reader->next();
		$this->assertSame( 'three', $entry->name );
		$this->assertSame( $resume, $entry->offset );
		$this->assertSame( 'third', $reader->read( 100 ) );
		$this->assertNull( $reader->next() );
		$reader->close();
	}

	public function test_missing_end_block_is_reported_as_incomplete(): void {
		$reader = WpressReader::open( $this->wpress( array( array( 'a', 'data' ) ), false ) );
		$reader->next();
		try {
			$reader->next();
			$this->fail( 'Expected an exception.' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'incomplete', $e->getMessage() );
		} finally {
			$reader->close();
		}
	}

	public function test_truncated_data_is_reported_before_reading_it(): void {
		$full = (string) file_get_contents( $this->wpress( array( array( 'big.bin', str_repeat( 'y', 5000 ) ) ) ) );
		$path = $this->make_file( 'cut.wpress', substr( $full, 0, WpressReader::HEADER_BYTES + 100 ) );

		$reader = WpressReader::open( $path );
		try {
			$reader->next();
			$this->fail( 'Expected an exception.' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'incomplete', $e->getMessage() );
		} finally {
			$reader->close();
		}
	}

	/**
	 * @dataProvider damaged_headers
	 *
	 * @param string $header Header bytes.
	 */
	public function test_damaged_headers_are_rejected( string $header ): void {
		$path   = $this->make_file( 'bad.wpress', $header . str_repeat( "\0", 100 + WpressReader::HEADER_BYTES ) );
		$reader = WpressReader::open( $path );
		try {
			$reader->next();
			$this->fail( 'Expected an exception.' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'amaged', $e->getMessage() );
		} finally {
			$reader->close();
		}
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function damaged_headers(): array {
		$good = self::header( 'a.txt', 10, 1700000000, '.' );
		return array(
			'non-digit size'       => array( substr_replace( $good, '1x', 255, 2 ) ),
			'garbage after name'   => array( substr_replace( $good, 'Z', 200, 1 ) ),
			'garbage after prefix' => array( substr_replace( $good, 'Z', 4000, 1 ) ),
			'empty name'           => array( substr_replace( $good, str_repeat( "\0", 255 ), 0, 255 ) ),
			'signed mtime'         => array( substr_replace( $good, '-1', 269, 2 ) ),
		);
	}

	public function test_v2_archives_carry_file_and_archive_checksums(): void {
		$path   = ( new WpressBuilder() )->add( 'package.json', '{}' )->add( 'uploads/a.txt', 'alpha' )->save( $this->tmp . '/v2.wpress' );
		$reader = WpressReader::open( $path );
		$this->assertSame( hash( 'crc32b', '{}' ), $reader->next()->crc32 );
		$this->assertSame( hash( 'crc32b', 'alpha' ), $reader->next()->crc32 );
		$this->assertNull( $reader->next() );
		$end = $reader->archive_crc();
		$reader->close();

		$data = (string) file_get_contents( $path );
		$this->assertSame( strlen( $data ) - WpressReader::HEADER_BYTES, $end['size'] );
		$this->assertSame( hash( 'crc32b', substr( $data, 0, -WpressReader::HEADER_BYTES ) ), $end['crc'] );
		$this->assertSame( $end, WpressReader::end_block( $path ) );
		$this->assertNull( WpressReader::end_block( $this->wpress( array( array( 'a', 'b' ) ) ) ) ); // v1: no checksum.
	}

	public function test_an_end_block_that_disagrees_with_the_archive_size_is_an_error(): void {
		$path = ( new WpressBuilder() )->add( 'uploads/a.txt', 'alpha' )->save( $this->tmp . '/v2.wpress' );
		$data = (string) file_get_contents( $path );
		$end  = strlen( $data ) - WpressReader::HEADER_BYTES;
		file_put_contents( $path, substr_replace( $data, pack( 'a14', (string) ( $end - 1 ) ), $end + WpressReader::NAME_BYTES, WpressReader::SIZE_BYTES ) );

		$reader = WpressReader::open( $path );
		$reader->next();
		try {
			$reader->next();
			$this->fail( 'Expected an exception.' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'damaged', $e->getMessage() );
		} finally {
			$reader->close();
		}
	}

	public function test_tar_and_random_files_are_not_mistaken_for_wpress(): void {
		$this->assertFalse( WpressReader::looks_like_wpress( $this->make_file( 'x.tar', str_repeat( "ustar\0", 2000 ) ) ) );
		$this->assertFalse( WpressReader::looks_like_wpress( $this->make_file( 'short', 'abc' ) ) );
		$this->assertTrue( WpressReader::looks_like_wpress( $this->wpress( array( array( 'package.json', '{}' ) ) ) ) );
	}
}
