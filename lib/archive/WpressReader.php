<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Archive;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming multi-GB files requires direct file handles.

/**
 * Sequential, seekable reader for All-in-One WP Migration (.wpress) archives.
 *
 * A .wpress file is a list of entries, each a 4377-byte header followed by
 * the stored data, closed by an end block:
 *
 *     name    255 bytes  file name, NUL-padded
 *     size     14 bytes  decimal stored size, NUL-padded
 *     mtime    12 bytes  decimal Unix time, NUL-padded
 *     path   4088 bytes  folder relative to wp-content ("." for the top), NUL-padded
 *     crc32     8 bytes  CRC-32 (hex) of the original content; empty in older archives
 *
 * Archives from All-in-One WP Migration before the CRC era ("v1") have a
 * 4096-byte path field and an end block of NUL bytes. "v2" archives end with
 * a block holding the archive size and the CRC-32 of everything before it
 * (see archive_crc()). Both are read the same way, since v1 paths never
 * reach the last 8 bytes.
 *
 * Headers are validated strictly (digits only, NUL padding only after the
 * value), so a damaged or truncated archive is reported instead of being
 * misread. Entry names are returned as stored; callers pass them through
 * PathGuard before touching the disk.
 *
 * Usage:
 *     $reader = WpressReader::open( $path );          // or open( $path, $offset ) to resume
 *     while ( $entry = $reader->next() ) {
 *         $data = $reader->read( 8192 );              // Optional; unread data is skipped.
 *     }
 *     $reader->close();
 */
final class WpressReader {

	const HEADER_BYTES = 4377;
	const NAME_BYTES   = 255;
	const SIZE_BYTES   = 14;
	const MTIME_BYTES  = 12;
	const PATH_BYTES   = 4088;
	const CRC_BYTES    = 8;

	/**
	 * Open file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Archive size.
	 *
	 * @var int
	 */
	private $length;

	/**
	 * Offset of the next header once the current entry's data is consumed.
	 *
	 * @var int
	 */
	private $next_offset;

	/**
	 * Unread data bytes of the current entry.
	 *
	 * @var int
	 */
	private $remaining = 0;

	/**
	 * Whether the end block was reached.
	 *
	 * @var bool
	 */
	private $ended = false;

	/**
	 * From a v2 end block: CRC-32 (hex) and size of the archive before the end block.
	 *
	 * @var array{crc:string,size:int}|null
	 */
	private $archive_crc = null;

	/**
	 * Constructor.
	 *
	 * @param resource $handle Open, seekable handle positioned at $offset.
	 * @param int      $length Archive size.
	 * @param int      $offset Header offset to start from.
	 */
	private function __construct( $handle, int $length, int $offset ) {
		$this->handle      = $handle;
		$this->length      = $length;
		$this->next_offset = $offset;
	}

	/**
	 * Opens an archive, optionally at the header offset returned by position().
	 *
	 * @param string $path   Archive path.
	 * @param int    $offset Header offset to resume from.
	 * @return self
	 * @throws ArchiveException When the file cannot be opened.
	 */
	public static function open( string $path, int $offset = 0 ): self {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new ArchiveException( sprintf( 'Cannot open %s.', $path ) );
		}
		$stat = fstat( $handle );
		if ( false === $stat || $offset < 0 || $offset > (int) $stat['size'] || 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			throw new ArchiveException( sprintf( 'Cannot read %s at offset %d.', $path, $offset ) );
		}
		return new self( $handle, (int) $stat['size'], $offset );
	}

	/**
	 * Quick check of the first header, without reading any data.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function looks_like_wpress( string $path ): bool {
		try {
			$reader = self::open( $path );
		} catch ( ArchiveException $e ) {
			return false;
		}
		try {
			return null !== $reader->next();
		} catch ( ArchiveException $e ) {
			return false;
		} finally {
			$reader->close();
		}
	}

	/**
	 * Header offset of the entry after the current one: pass it to open() to resume there.
	 *
	 * @return int
	 */
	public function position(): int {
		return $this->next_offset;
	}

	/**
	 * Whether the end block has been read.
	 *
	 * @return bool
	 */
	public function ended(): bool {
		return $this->ended;
	}

	/**
	 * CRC-32 and covered size from a v2 end block (after next() returned null), else null.
	 *
	 * @return array{crc:string,size:int}|null
	 */
	public function archive_crc(): ?array {
		return $this->archive_crc;
	}

	/**
	 * Reads the end block of an archive without walking it.
	 *
	 * @param string $path Archive path.
	 * @return array{crc:string,size:int}|null CRC data for v2 archives, null for v1.
	 * @throws ArchiveException When the file does not end with an end block.
	 */
	public static function end_block( string $path ): ?array {
		$size = filesize( $path );
		if ( false === $size || $size < self::HEADER_BYTES ) {
			throw new ArchiveException( 'The archive is too short to be a .wpress file.' );
		}
		$reader = self::open( $path, $size - self::HEADER_BYTES );
		try {
			$block = $reader->read_exact( self::HEADER_BYTES );
		} finally {
			$reader->close();
		}
		$end = self::parse_end( $block, $size - self::HEADER_BYTES );
		if ( false === $end ) {
			throw new ArchiveException( 'The archive does not end with a .wpress end block; it is incomplete (truncated upload or copy?).' );
		}
		return $end;
	}

	/**
	 * Advances to the next entry, skipping unread data of the current one.
	 *
	 * @return WpressEntry|null Null at the end block.
	 * @throws ArchiveException On a truncated archive or a damaged header.
	 */
	public function next(): ?WpressEntry {
		if ( $this->ended ) {
			return null;
		}
		if ( $this->remaining > 0 && 0 !== fseek( $this->handle(), $this->next_offset ) ) {
			throw new ArchiveException( 'Cannot seek in the archive.' );
		}
		$this->remaining = 0;

		$offset = $this->next_offset;
		$header = $this->read_exact( self::HEADER_BYTES );
		if ( strlen( $header ) < self::HEADER_BYTES ) {
			throw new ArchiveException( sprintf( 'The archive ends at byte %d without its end block; it is incomplete (truncated upload or copy?).', $offset + strlen( $header ) ) );
		}
		$end = self::parse_end( $header, $offset );
		if ( false !== $end ) {
			if ( null !== $end && $end['size'] !== $offset ) {
				throw new ArchiveException( sprintf( 'The end block says the archive is %d bytes long, but it ends at byte %d; the archive is damaged.', $end['size'], $offset ) );
			}
			$this->ended       = true;
			$this->archive_crc = $end;
			$this->next_offset = $offset + self::HEADER_BYTES;
			return null;
		}

		$name   = self::field( $header, 0, self::NAME_BYTES, $offset, 'name' );
		$size   = self::field( $header, self::NAME_BYTES, self::SIZE_BYTES, $offset, 'size' );
		$mtime  = self::field( $header, self::NAME_BYTES + self::SIZE_BYTES, self::MTIME_BYTES, $offset, 'mtime' );
		$prefix = self::field( $header, self::NAME_BYTES + self::SIZE_BYTES + self::MTIME_BYTES, self::PATH_BYTES, $offset, 'path' );
		$crc    = self::field( $header, self::HEADER_BYTES - self::CRC_BYTES, self::CRC_BYTES, $offset, 'crc32' );

		if ( '' === $name || ! ctype_digit( $size ) || ( '' !== $mtime && ! ctype_digit( $mtime ) ) || ( '' !== $crc && ! ctype_xdigit( $crc ) ) || ( '' !== $crc && self::CRC_BYTES !== strlen( $crc ) ) ) {
			throw new ArchiveException( sprintf( 'Damaged .wpress header at byte %d.', $offset ) );
		}

		$bytes = (int) $size;
		$data  = $offset + self::HEADER_BYTES;
		if ( $bytes > $this->length - $data ) {
			throw new ArchiveException( sprintf( 'Entry "%s" needs %d bytes but the archive ends first; it is incomplete (truncated upload or copy?).', self::printable( $name ), $bytes ) );
		}

		$this->remaining   = $bytes;
		$this->next_offset = $data + $bytes;

		$prefix = trim( $prefix, '/' );
		$path   = ( '' === $prefix || '.' === $prefix ) ? $name : $prefix . '/' . $name;
		return new WpressEntry( $path, $bytes, (int) $mtime, $offset, strtolower( $crc ) );
	}

	/**
	 * Reads up to $length bytes of the current entry's data.
	 *
	 * @param int $length Maximum bytes.
	 * @return string Empty at the end of the entry.
	 * @throws ArchiveException On a read error.
	 */
	public function read( int $length ): string {
		$length = min( $length, $this->remaining );
		if ( $length <= 0 ) {
			return '';
		}
		$data = $this->read_exact( $length );
		if ( strlen( $data ) !== $length ) {
			throw new ArchiveException( 'Unexpected end of the archive.' );
		}
		$this->remaining -= $length;
		return $data;
	}

	/**
	 * Skips $length bytes of the current entry's data.
	 *
	 * @param int $length Bytes.
	 * @return void
	 * @throws ArchiveException On a seek error or when skipping past the entry.
	 */
	public function skip_bytes( int $length ): void {
		if ( $length > $this->remaining ) {
			throw new ArchiveException( 'Cannot skip past the end of the entry.' );
		}
		if ( $length > 0 && 0 !== fseek( $this->handle(), $length, SEEK_CUR ) ) {
			throw new ArchiveException( 'Cannot seek in the archive.' );
		}
		$this->remaining -= $length;
	}

	/**
	 * Unread data bytes of the current entry.
	 *
	 * @return int
	 */
	public function remaining(): int {
		return $this->remaining;
	}

	/**
	 * Closes the file.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Open handle.
	 *
	 * @return resource
	 * @throws ArchiveException When the reader is closed.
	 */
	private function handle() {
		if ( null === $this->handle ) {
			throw new ArchiveException( 'The archive reader is closed.' );
		}
		return $this->handle;
	}

	/**
	 * Recognises an end block.
	 *
	 * @param string $block  Header-sized block.
	 * @param int    $offset Its offset, for messages.
	 * @return array{crc:string,size:int}|null|false False when not an end block, null for a v1 (all NUL) one.
	 * @throws ArchiveException On a damaged v2 end block.
	 */
	private static function parse_end( string $block, int $offset ) {
		if ( str_repeat( "\0", self::HEADER_BYTES ) === $block ) {
			return null;
		}
		// v2: NUL name, the archive size, NUL mtime and path, then the archive CRC-32.
		if ( str_repeat( "\0", self::NAME_BYTES ) !== substr( $block, 0, self::NAME_BYTES )
			|| str_repeat( "\0", self::MTIME_BYTES + self::PATH_BYTES ) !== substr( $block, self::NAME_BYTES + self::SIZE_BYTES, self::MTIME_BYTES + self::PATH_BYTES ) ) {
			return false;
		}
		$size = self::field( $block, self::NAME_BYTES, self::SIZE_BYTES, $offset, 'size' );
		$crc  = self::field( $block, self::HEADER_BYTES - self::CRC_BYTES, self::CRC_BYTES, $offset, 'crc32' );
		if ( ! ctype_digit( $size ) || self::CRC_BYTES !== strlen( $crc ) || ! ctype_xdigit( $crc ) ) {
			throw new ArchiveException( sprintf( 'Damaged .wpress end block at byte %d.', $offset ) );
		}
		return array(
			'crc'  => strtolower( $crc ),
			'size' => (int) $size,
		);
	}

	/**
	 * Reads exactly $length bytes unless the file ends first.
	 *
	 * @param int $length Bytes.
	 * @return string
	 */
	private function read_exact( int $length ): string {
		$data = '';
		while ( strlen( $data ) < $length ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- $data grows each pass.
			$chunk = fread( $this->handle(), $length - strlen( $data ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
		}
		return $data;
	}

	/**
	 * One NUL-padded header field; anything but NUL after the value means a damaged header.
	 *
	 * @param string $header Header.
	 * @param int    $start  Field start.
	 * @param int    $length Field length.
	 * @param int    $offset Header offset, for the message.
	 * @param string $label  Field name, for the message.
	 * @return string
	 * @throws ArchiveException On a damaged field.
	 */
	private static function field( string $header, int $start, int $length, int $offset, string $label ): string {
		$raw = substr( $header, $start, $length );
		$end = strpos( $raw, "\0" );
		if ( false === $end ) {
			return $raw;
		}
		if ( strspn( $raw, "\0", $end ) !== $length - $end ) {
			throw new ArchiveException( sprintf( 'Damaged .wpress header at byte %d (%s field).', $offset, $label ) );
		}
		return substr( $raw, 0, $end );
	}

	/**
	 * Printable form of a name for messages.
	 *
	 * @param string $value Name.
	 * @return string
	 */
	private static function printable( string $value ): string {
		return (string) preg_replace( '/[^\x20-\x7E]/', '?', $value );
	}
}
