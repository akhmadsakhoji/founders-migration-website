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
use Founders\Migration\Archive\PlainFileSink;
use Founders\Migration\Archive\TarEntry;
use Founders\Migration\Archive\TarReader;
use Founders\Migration\Archive\TarWriter;
use Founders\Migration\Tests\TestCase;

final class TarWriterTest extends TestCase {

	/**
	 * Names that exercise ustar, PAX long names and UTF-8.
	 *
	 * @return array<string,string> Archive name => contents.
	 */
	private function fixture_files(): array {
		return array(
			'plugins/hello/hello.php'                         => "<?php\necho 'hello';\n",
			'uploads/2026/09/foto-ñ-日本.jpg'                => random_bytes( 3000 ),
			str_repeat( 'nested/', 20 ) . 'deep-file.txt'   => str_repeat( 'x', 512 ),
			'uploads/empty.txt'                               => '',
			'themes/astra/style.css'                          => str_repeat( "body{margin:0}\n", 400 ),
		);
	}

	/**
	 * Writes the fixture with the given sink class and returns [archive path, files].
	 *
	 * @param string $sink_class Sink class.
	 * @param string $archive    Archive file name.
	 * @return array{0:string,1:array<string,string>}
	 */
	private function build_archive( string $sink_class, string $archive ): array {
		$files  = $this->fixture_files();
		$path   = $this->tmp . '/' . $archive;
		$writer = new TarWriter( new $sink_class( $path ) );

		$writer->add_directory( 'uploads', 0755, 1700000000 );
		$i = 0;
		foreach ( $files as $name => $contents ) {
			$source = $this->make_file( 'src/' . ( $i++ ), $contents );
			$writer->add_file( $source, TarEntry::file( $name, strlen( $contents ), 0644, 1700000000 ) );
			$writer->commit();
		}
		$writer->add_symlink( 'uploads/latest.jpg', '2026/09/foto-ñ-日本.jpg', 1700000000 );
		$writer->add_string( 'manifest.json', '{"format":"fmw"}' );
		$writer->finish();

		return array( $path, $files );
	}

	public function test_php_reader_round_trip_plain_and_gzip(): void {
		foreach ( array( PlainFileSink::class => 'a.tar', GzipFileSink::class => 'a.tar.gz' ) as $sink => $name ) {
			list( $path, $files ) = $this->build_archive( $sink, $name );

			$reader = TarReader::open( $path );
			$seen   = array();
			while ( null !== ( $entry = $reader->next() ) ) {
				$seen[ $entry->name ] = $entry;
				if ( $entry->is_file() && isset( $files[ $entry->name ] ) ) {
					$this->assertSame( $files[ $entry->name ], $reader->read( PHP_INT_MAX ), $entry->name );
				}
			}
			$reader->close();

			foreach ( array_keys( $files ) as $file ) {
				$this->assertArrayHasKey( $file, $seen, $file );
			}
			$this->assertTrue( $seen['uploads/']->is_directory() );
			$this->assertTrue( $seen['uploads/latest.jpg']->is_symlink() );
			$this->assertSame( '2026/09/foto-ñ-日本.jpg', $seen['uploads/latest.jpg']->linkname );
			$this->assertArrayHasKey( 'manifest.json', $seen );
		}
	}

	public function test_gnu_tar_reads_plain_and_multi_member_gzip_archives(): void {
		if ( null === $this->tool( 'tar' ) ) {
			$this->markTestSkipped( 'tar is not installed.' );
		}

		foreach ( array( PlainFileSink::class => 'b.tar', GzipFileSink::class => 'b.tar.gz' ) as $sink => $name ) {
			list( $path, $files ) = $this->build_archive( $sink, $name );
			$out                  = $this->tmp . '/out-' . $name;
			mkdir( $out );

			list( $code, $output ) = $this->run_command( 'tar -xf ' . escapeshellarg( $path ) . ' -C ' . escapeshellarg( $out ) );
			$this->assertSame( 0, $code, $output );

			foreach ( $files as $file => $contents ) {
				$this->assertSame( $contents, file_get_contents( $out . '/' . $file ), $file );
			}
			$this->assertSame( '2026/09/foto-ñ-日本.jpg', readlink( $out . '/uploads/latest.jpg' ) );
		}
	}

	public function test_bsdtar_reads_the_archive(): void {
		if ( null === $this->tool( 'bsdtar' ) ) {
			$this->markTestSkipped( 'bsdtar is not installed.' );
		}

		list( $path, $files ) = $this->build_archive( GzipFileSink::class, 'c.tar.gz' );
		$out                  = $this->tmp . '/out-bsd';
		mkdir( $out );

		list( $code, $output ) = $this->run_command( 'bsdtar -xf ' . escapeshellarg( $path ) . ' -C ' . escapeshellarg( $out ) );
		$this->assertSame( 0, $code, $output );
		foreach ( $files as $file => $contents ) {
			$this->assertSame( $contents, file_get_contents( $out . '/' . $file ), $file );
		}
	}

	public function test_file_written_in_slices_across_writers_equals_one_shot(): void {
		$contents = random_bytes( 3 * 1048576 + 123 );
		$source   = $this->make_file( 'big.bin', $contents );
		$entry    = TarEntry::file( 'uploads/big.bin', strlen( $contents ) );

		$one_shot = $this->tmp . '/one.tar';
		$writer   = new TarWriter( new PlainFileSink( $one_shot ) );
		$writer->add_file( $source, $entry );
		$writer->finish();

		// Simulate three separate requests, each resuming from the committed state.
		$sliced    = $this->tmp . '/sliced.tar';
		$offset    = 0;
		$committed = 0;
		while ( $offset < $entry->size ) {
			$writer    = new TarWriter( new PlainFileSink( $sliced, $committed ) );
			$offset    = $writer->write_file_slice( $source, $entry, $offset, 1048576 + 7 );
			$committed = $offset < $entry->size ? $writer->commit() : $writer->finish();
		}

		$this->assertSame( sha1_file( $one_shot ), sha1_file( $sliced ) );
	}

	public function test_shrunken_file_keeps_archive_valid_and_warns(): void {
		$source = $this->make_file( 'shrinks.txt', 'short' );
		$entry  = TarEntry::file( 'shrinks.txt', 2000 ); // Scanned when the file was larger.

		$path   = $this->tmp . '/s.tar';
		$writer = new TarWriter( new PlainFileSink( $path ) );
		$writer->add_file( $source, $entry );
		$writer->add_string( 'after.txt', 'still readable' );
		$writer->finish();

		$this->assertCount( 1, $writer->warnings() );

		$reader = TarReader::open( $path );
		$first  = $reader->next();
		$this->assertSame( 2000, $first->size );
		$this->assertSame( 'short' . str_repeat( "\0", 1995 ), $reader->read( PHP_INT_MAX ) );
		$this->assertSame( 'after.txt', $reader->next()->name );
		$this->assertSame( 'still readable', $reader->read( 100 ) );
	}

	public function test_unreadable_file_fails_before_writing_a_header(): void {
		$path   = $this->tmp . '/u.tar';
		$writer = new TarWriter( new PlainFileSink( $path ) );

		try {
			$writer->add_file( $this->tmp . '/missing.txt', TarEntry::file( 'missing.txt', 10 ) );
			$this->fail( 'Expected an exception.' );
		} catch ( ArchiveException $e ) {
			$this->assertSame( 0, $writer->commit() );
		}
	}
}
