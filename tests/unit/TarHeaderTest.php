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
use Founders\Migration\Archive\TarEntry;
use Founders\Migration\Archive\TarHeader;
use Founders\Migration\Tests\TestCase;

final class TarHeaderTest extends TestCase {

	public function test_short_ascii_name_is_a_single_ustar_block(): void {
		$header = TarHeader::build( TarEntry::file( 'uploads/a.txt', 5, 0640, 1700000000 ) );

		$this->assertSame( 512, strlen( $header ) );
		$parsed = TarHeader::parse( $header );
		$this->assertSame( 'uploads/a.txt', $parsed['name'] );
		$this->assertSame( 5, $parsed['size'] );
		$this->assertSame( 0640, $parsed['mode'] );
		$this->assertSame( 1700000000, $parsed['mtime'] );
		$this->assertSame( TarEntry::TYPE_FILE, $parsed['type'] );
	}

	public function test_owner_fields_are_anonymous(): void {
		$header = TarHeader::build( TarEntry::file( 'a', 1 ) );

		$this->assertSame( "0000000\0", substr( $header, 108, 8 ), 'uid' );
		$this->assertSame( "0000000\0", substr( $header, 116, 8 ), 'gid' );
		$this->assertSame( str_repeat( "\0", 64 ), substr( $header, 265, 64 ), 'uname + gname' );
	}

	public function test_long_or_unicode_names_use_a_pax_header(): void {
		foreach ( array( str_repeat( 'd/', 60 ) . 'file.jpg', 'uploads/foto-pernikahan-ñ-日本.jpg' ) as $name ) {
			$header = TarHeader::build( TarEntry::file( $name, 10 ) );

			$this->assertSame( 0, strlen( $header ) % 512 );
			$this->assertGreaterThan( 512, strlen( $header ) );
			$pax = TarHeader::parse( substr( $header, 0, 512 ) );
			$this->assertSame( 'x', $pax['type'] );
			$records = TarHeader::parse_pax_records( substr( $header, 512, $pax['size'] ) );
			$this->assertSame( $name, $records['path'] );
		}
	}

	public function test_size_above_8_gib_is_stored_in_pax(): void {
		$size   = 100 * 1024 * 1024 * 1024; // 100 GiB.
		$header = TarHeader::build( TarEntry::file( 'video.mp4', $size ) );

		$pax     = TarHeader::parse( substr( $header, 0, 512 ) );
		$records = TarHeader::parse_pax_records( substr( $header, 512, $pax['size'] ) );
		$this->assertSame( (string) $size, $records['size'] );
	}

	public function test_pax_record_length_counts_its_own_digits(): void {
		// A payload of 98 bytes needs a 3-digit length (98 + 2 = 100 -> 101).
		$value   = str_repeat( 'a', 98 - strlen( ' path=' ) - 1 );
		$records = TarHeader::pax_records( array( 'path' => $value ) );

		$this->assertSame( strlen( $records ), (int) strtok( $records, ' ' ) );
		$this->assertSame( array( 'path' => $value ), TarHeader::parse_pax_records( $records ) );
	}

	public function test_zero_block_marks_the_end(): void {
		$this->assertNull( TarHeader::parse( str_repeat( "\0", 512 ) ) );
	}

	public function test_corrupted_header_is_rejected(): void {
		$header       = TarHeader::build( TarEntry::file( 'a.txt', 1 ) );
		$header[ 10 ] = 'X';

		$this->expectException( ArchiveException::class );
		TarHeader::parse( $header );
	}

	public function test_gnu_base256_size_is_parsed(): void {
		$header = TarHeader::build( TarEntry::file( 'big.bin', 0 ) );
		$size   = 20 * 1024 * 1024 * 1024;
		$field  = "\x80" . str_repeat( "\0", 3 ) . pack( 'J', $size );
		$header = substr_replace( $header, $field, 124, 12 );
		$header = substr_replace( $header, str_repeat( ' ', 8 ), 148, 8 );
		$sum    = array_sum( array_map( 'ord', str_split( $header ) ) );
		$header = substr_replace( $header, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );

		$this->assertSame( $size, TarHeader::parse( $header )['size'] );
	}
}
