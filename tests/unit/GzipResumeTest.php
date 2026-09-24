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
use Founders\Migration\Archive\GzipFileSink;
use Founders\Migration\Archive\TarEntry;
use Founders\Migration\Archive\TarReader;
use Founders\Migration\Archive\TarWriter;
use Founders\Migration\Tests\TestCase;

/**
 * The core promise of phase 0: a part interrupted at any point resumes from
 * the last commit and ends up as a valid archive.
 */
final class GzipResumeTest extends TestCase {

	public function test_every_commit_closes_a_gzip_member(): void {
		$path = $this->tmp . '/m.gz';
		$sink = new GzipFileSink( $path );
		foreach ( array( 'one ', 'two ', 'three' ) as $piece ) {
			$sink->write( $piece );
			$sink->commit();
		}
		$sink->close();

		$raw = file_get_contents( $path );
		$this->assertSame( 3, substr_count( $raw, "\x1f\x8b\x08" ), 'three gzip members' );
		$this->assertSame( 'one two three', gzdecode_all( $raw ) );

		if ( null !== $this->tool( 'gzip' ) ) {
			list( $code, $output ) = $this->run_command( 'gzip -t ' . escapeshellarg( $path ) );
			$this->assertSame( 0, $code, $output );
		}
	}

	public function test_commit_without_new_data_adds_no_empty_member(): void {
		$path = $this->tmp . '/e.gz';
		$sink = new GzipFileSink( $path );
		$sink->write( 'data' );
		$first = $sink->commit();
		$this->assertSame( $first, $sink->commit() );
		$sink->close();
	}

	public function test_crash_after_commit_resumes_into_a_valid_archive(): void {
		$files = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$files[ "uploads/file-$i.bin" ] = random_bytes( 200000 + $i );
		}
		$sources = array();
		foreach ( $files as $name => $contents ) {
			$sources[ $name ] = $this->make_file( 'src/' . basename( $name ), $contents );
		}
		$names = array_keys( $files );
		$path  = $this->tmp . '/part-0001.tar.gz';

		// Process 1: writes three files, commits, then starts a fourth and "dies".
		$writer = new TarWriter( new GzipFileSink( $path ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$writer->add_file( $sources[ $names[ $i ] ], TarEntry::file( $names[ $i ], strlen( $files[ $names[ $i ] ] ) ) );
		}
		$checkpoint = $writer->commit();
		$writer->write_file_slice( $sources[ $names[3] ], TarEntry::file( $names[3], strlen( $files[ $names[3] ] ) ), 0, 50000 );
		unset( $writer ); // No commit: simulates a timeout or a killed process.
		file_put_contents( $path, random_bytes( 777 ), FILE_APPEND ); // Torn write garbage.

		// Process 2: resumes at the checkpoint and finishes the part.
		$writer = new TarWriter( new GzipFileSink( $path, $checkpoint ) );
		for ( $i = 3; $i < 6; $i++ ) {
			$writer->add_file( $sources[ $names[ $i ] ], TarEntry::file( $names[ $i ], strlen( $files[ $names[ $i ] ] ) ) );
		}
		$writer->finish();

		$reader = TarReader::open( $path );
		$seen   = array();
		while ( null !== ( $entry = $reader->next() ) ) {
			$seen[ $entry->name ] = $reader->read( PHP_INT_MAX );
		}
		$this->assertSame( $files, $seen );

		if ( null !== $this->tool( 'tar' ) ) {
			list( $code, $output ) = $this->run_command( 'tar -tzf ' . escapeshellarg( $path ) );
			$this->assertSame( 0, $code, $output );
			$this->assertSame( $names, explode( "\n", trim( $output, " \n\r\t\v\0" ) ) );
		}
	}

	public function test_resume_beyond_end_of_file_is_refused(): void {
		$path = $this->make_file( 'short.gz', 'abc' );
		$this->expectException( ArchiveException::class );
		new GzipFileSink( $path, 10 );
	}
}

/**
 * Decodes a (multi-member) gzip string.
 *
 * @param string $data Gzip data.
 * @return string
 */
function gzdecode_all( string $data ): string {
	$ctx = inflate_init( ZLIB_ENCODING_GZIP );
	$out = '';
	while ( '' !== $data ) {
		$out .= inflate_add( $ctx, $data, ZLIB_FINISH );
		$used = inflate_get_read_len( $ctx );
		$data = substr( $data, $used );
		$ctx  = inflate_init( ZLIB_ENCODING_GZIP );
	}
	return $out;
}
