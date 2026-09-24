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
 * Sequential TAR reader for plain and gzip-compressed (including multi-member) archives.
 *
 * Understands ustar, PAX ('x' / 'g') and GNU long names ('L' / 'K').
 *
 * Usage:
 *     $reader = TarReader::open( $path );
 *     while ( $entry = $reader->next() ) {
 *         $data = $reader->read( 8192 ); // Optional; unread data is skipped.
 *     }
 *     $reader->close();
 */
final class TarReader {

	/**
	 * Open stream.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Whether the stream supports cheap forward seeks.
	 *
	 * @var bool
	 */
	private $seekable;

	/**
	 * Unread data bytes of the current entry.
	 *
	 * @var int
	 */
	private $remaining = 0;

	/**
	 * Padding after the current entry's data.
	 *
	 * @var int
	 */
	private $padding = 0;

	/**
	 * Opens a .tar or .tar.gz file (detected from the gzip magic bytes).
	 *
	 * @param string $path File path.
	 * @return self
	 * @throws ArchiveException When the file cannot be opened.
	 */
	public static function open( string $path ): self {
		$probe = fopen( $path, 'rb' );
		if ( false === $probe ) {
			throw new ArchiveException( sprintf( 'Cannot open %s.', $path ) );
		}
		$magic = (string) fread( $probe, 2 );
		fclose( $probe );

		if ( "\x1f\x8b" === $magic ) {
			$handle = fopen( 'compress.zlib://' . $path, 'rb' );
			if ( false === $handle ) {
				throw new ArchiveException( sprintf( 'Cannot open %s as gzip.', $path ) );
			}
			return new self( $handle, false );
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new ArchiveException( sprintf( 'Cannot open %s.', $path ) );
		}
		return new self( $handle, true );
	}

	/**
	 * Constructor.
	 *
	 * @param resource $handle   Readable stream positioned at the first header.
	 * @param bool     $seekable Whether fseek() may be used to skip data.
	 */
	public function __construct( $handle, bool $seekable ) {
		$this->handle   = $handle;
		$this->seekable = $seekable;
	}

	/**
	 * Advances to the next entry, skipping any unread data of the current one.
	 *
	 * @return TarEntry|null Null at the end of the archive.
	 * @throws ArchiveException On a malformed archive.
	 */
	public function next(): ?TarEntry {
		$this->skip( $this->remaining + $this->padding );
		$this->remaining = 0;
		$this->padding   = 0;

		$pax      = array();
		$gnu_name = null;
		$gnu_link = null;

		while ( true ) {
			$block = $this->read_exact( TarHeader::BLOCK_SIZE, true );
			if ( null === $block ) {
				return null;
			}

			$header = TarHeader::parse( $block );
			if ( null === $header ) {
				return null;
			}

			switch ( $header['type'] ) {
				case 'x':
					$pax = array_merge( $pax, TarHeader::parse_pax_records( $this->read_meta( $header['size'] ) ) );
					continue 2;
				case 'g':
					$this->read_meta( $header['size'] );
					continue 2;
				case 'L':
					$gnu_name = rtrim( $this->read_meta( $header['size'] ), "\0" );
					continue 2;
				case 'K':
					$gnu_link = rtrim( $this->read_meta( $header['size'] ), "\0" );
					continue 2;
			}

			$name     = $pax['path'] ?? $gnu_name ?? $header['name'];
			$linkname = $pax['linkpath'] ?? $gnu_link ?? $header['linkname'];
			$size     = isset( $pax['size'] ) ? (int) $pax['size'] : $header['size'];
			$mtime    = isset( $pax['mtime'] ) ? (int) $pax['mtime'] : $header['mtime'];

			if ( $size < 0 ) {
				throw new ArchiveException( 'Negative entry size.' );
			}

			$this->remaining = $size;
			$this->padding   = TarHeader::padding( $size );

			return new TarEntry( $name, $header['type'], $size, $header['mode'], $mtime, $linkname );
		}
	}

	/**
	 * Reads up to $length bytes of the current entry's data.
	 *
	 * @param int $length Maximum bytes.
	 * @return string Empty string when the entry's data is exhausted.
	 */
	public function read( int $length ): string {
		$length = (int) min( $length, $this->remaining );
		if ( $length <= 0 ) {
			return '';
		}
		$data             = (string) $this->read_exact( $length );
		$this->remaining -= strlen( $data );
		return $data;
	}

	/**
	 * Skips up to $length bytes of the current entry's data.
	 *
	 * @param int $length Bytes to skip.
	 * @return void
	 */
	public function skip_bytes( int $length ): void {
		$length = (int) min( $length, $this->remaining );
		if ( $length > 0 ) {
			$this->skip( $length );
			$this->remaining -= $length;
		}
	}

	/**
	 * Copies the rest of the current entry's data to a writable stream.
	 *
	 * @param resource $destination Writable stream.
	 * @return int Bytes copied.
	 * @throws ArchiveException On a failed write.
	 */
	public function copy_to( $destination ): int {
		$copied = 0;
		while ( $this->remaining > 0 ) {
			$chunk = $this->read( 1048576 );
			if ( false === fwrite( $destination, $chunk ) ) {
				throw new ArchiveException( 'Write failed. The disk may be full.' );
			}
			$copied += strlen( $chunk );
		}
		return $copied;
	}

	/**
	 * Closes the stream.
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
	 * Reads a metadata payload (PAX / GNU long name) plus its padding.
	 *
	 * @param int $size Payload size.
	 * @return string
	 * @throws ArchiveException When the payload is unreasonably large.
	 */
	private function read_meta( int $size ): string {
		if ( $size > TarHeader::MAX_META_SIZE ) {
			throw new ArchiveException( 'TAR metadata entry is too large.' );
		}
		$data = (string) $this->read_exact( $size );
		$this->skip( TarHeader::padding( $size ) );
		return $data;
	}

	/**
	 * Reads exactly $length bytes.
	 *
	 * @param int  $length    Byte count.
	 * @param bool $allow_eof Return null instead of failing when nothing is left.
	 * @return string|null
	 * @throws ArchiveException On a truncated archive.
	 */
	private function read_exact( int $length, bool $allow_eof = false ): ?string {
		if ( null === $this->handle ) {
			throw new ArchiveException( 'Reader is closed.' );
		}
		if ( 0 === $length ) {
			return '';
		}

		$data = '';
		$read = 0;
		while ( $read < $length ) {
			$chunk = fread( $this->handle, $length - $read );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
			$read += strlen( $chunk );
		}

		if ( 0 === $read && $allow_eof ) {
			return null;
		}
		if ( $read !== $length ) {
			throw new ArchiveException( 'Unexpected end of archive.' );
		}
		return $data;
	}

	/**
	 * Skips $length bytes forward.
	 *
	 * @param int $length Byte count.
	 * @return void
	 * @throws ArchiveException On a truncated archive.
	 */
	private function skip( int $length ): void {
		if ( $length <= 0 || null === $this->handle ) {
			return;
		}
		if ( $this->seekable ) {
			if ( 0 !== fseek( $this->handle, $length, SEEK_CUR ) ) {
				throw new ArchiveException( 'Unexpected end of archive.' );
			}
			return;
		}
		while ( $length > 0 ) {
			$step    = (int) min( 1048576, $length );
			$length -= strlen( (string) $this->read_exact( $step ) );
		}
	}
}
