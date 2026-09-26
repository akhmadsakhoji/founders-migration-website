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

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming multi-GB files requires direct file handles.

/**
 * Streams entries into a TAR (PAX) archive through a Sink.
 *
 * Large files can be written in slices across separate requests or processes:
 * write_file_slice() returns the new offset, the caller commits the sink and
 * stores both offsets, and a later process continues from there.
 */
final class TarWriter {

	const READ_CHUNK = 1048576;

	/**
	 * Destination.
	 *
	 * @var Sink
	 */
	private $sink;

	/**
	 * Non-fatal problems met while writing.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param Sink $sink Destination.
	 */
	public function __construct( Sink $sink ) {
		$this->sink = $sink;
	}

	/**
	 * Adds a directory entry.
	 *
	 * @param string $name  Path inside the archive.
	 * @param int    $mode  Permission bits.
	 * @param int    $mtime Modification time.
	 * @return void
	 */
	public function add_directory( string $name, int $mode = 0755, int $mtime = 0 ): void {
		$this->sink->write( TarHeader::build( TarEntry::directory( $name, $mode, $mtime ) ) );
	}

	/**
	 * Adds a symbolic link entry (the link itself, not its target).
	 *
	 * @param string $name   Path inside the archive.
	 * @param string $target Link target as stored on disk.
	 * @param int    $mtime  Modification time.
	 * @return void
	 */
	public function add_symlink( string $name, string $target, int $mtime = 0 ): void {
		$this->sink->write( TarHeader::build( TarEntry::symlink( $name, $target, $mtime ) ) );
	}

	/**
	 * Adds a file from an in-memory string (manifests, small metadata).
	 *
	 * @param string $name  Path inside the archive.
	 * @param string $data  Contents.
	 * @param int    $mode  Permission bits.
	 * @param int    $mtime Modification time.
	 * @return void
	 */
	public function add_string( string $name, string $data, int $mode = 0644, int $mtime = 0 ): void {
		$size = strlen( $data );
		$this->sink->write( TarHeader::build( TarEntry::file( $name, $size, $mode, $mtime ) ) );
		$this->sink->write( $data );
		$this->write_padding( $size );
	}

	/**
	 * Starts a file entry whose data the caller writes with write_data() (for data produced on the fly).
	 *
	 * @param TarEntry $entry Entry; its size must be exact.
	 * @return void
	 */
	public function begin_file( TarEntry $entry ): void {
		$this->sink->write( TarHeader::build( $entry ) );
	}

	/**
	 * Writes data of the entry started with begin_file().
	 *
	 * @param string $bytes Bytes.
	 * @return void
	 */
	public function write_data( string $bytes ): void {
		$this->sink->write( $bytes );
	}

	/**
	 * Ends an entry started with begin_file() (block padding).
	 *
	 * @param TarEntry $entry The same entry.
	 * @return void
	 */
	public function end_file( TarEntry $entry ): void {
		$this->write_padding( $entry->size );
	}

	/**
	 * Adds a whole file in one call.
	 *
	 * @param string   $source Path on disk.
	 * @param TarEntry $entry  Entry metadata (size taken from the file scan).
	 * @return void
	 */
	public function add_file( string $source, TarEntry $entry ): void {
		$this->write_file_slice( $source, $entry, 0, PHP_INT_MAX );
	}

	/**
	 * Writes up to $budget bytes of a file, starting at $offset.
	 *
	 * The header is written when $offset is 0 and the padding when the last
	 * byte is reached. The declared size in $entry is always honoured so the
	 * archive stays valid: a file that shrank is padded with zeros and a file
	 * that grew is cut at the declared size; both cases add a warning.
	 *
	 * @param string   $source Path on disk.
	 * @param TarEntry $entry  Entry metadata.
	 * @param int      $offset Bytes of this file already written.
	 * @param int      $budget Maximum data bytes to write in this call (> 0).
	 * @return int New offset. The entry is complete when it equals $entry->size.
	 * @throws \InvalidArgumentException On an invalid offset or budget.
	 * @throws UnreadableFileException When the file cannot be read; nothing has been written then.
	 */
	public function write_file_slice( string $source, TarEntry $entry, int $offset, int $budget ): int {
		if ( $budget < 1 ) {
			throw new \InvalidArgumentException( 'Budget must be positive.' );
		}
		if ( $offset < 0 || $offset > $entry->size ) {
			throw new \InvalidArgumentException( 'Offset is outside the file.' );
		}

		$handle = is_readable( $source ) ? fopen( $source, 'rb' ) : false;
		if ( 0 === $offset ) {
			if ( false === $handle ) {
				throw new UnreadableFileException( sprintf( 'Cannot read %s.', $source ) );
			}
			$this->sink->write( TarHeader::build( $entry ) );
		}
		if ( false !== $handle && $offset > 0 && 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			$handle = false;
		}

		$end     = ( $entry->size - $offset <= $budget ) ? $entry->size : $offset + $budget;
		$pos     = $offset;
		$changed = false;

		while ( $pos < $end ) {
			$want  = (int) min( self::READ_CHUNK, $end - $pos );
			$chunk = false !== $handle ? fread( $handle, $want ) : false;

			if ( false === $chunk || '' === $chunk ) {
				$changed = true;
				$chunk   = str_repeat( "\0", $want );
				if ( false !== $handle ) {
					fclose( $handle );
					$handle = false;
				}
			}

			$this->sink->write( $chunk );
			$pos += strlen( $chunk );
		}

		if ( false !== $handle ) {
			$stat = fstat( $handle );
			if ( $pos === $entry->size && false !== $stat && (int) $stat['size'] !== $entry->size ) {
				$changed = true;
			}
			fclose( $handle );
		}

		if ( $changed ) {
			$this->warnings[] = sprintf( '%s changed size during backup; stored %d bytes as scanned.', $entry->name, $entry->size );
		}

		if ( $pos === $entry->size ) {
			$this->write_padding( $entry->size );
		}

		return $pos;
	}

	/**
	 * Makes the archive so far durable. See Sink::commit().
	 *
	 * @return int Committed offset of the underlying file.
	 */
	public function commit(): int {
		return $this->sink->commit();
	}

	/**
	 * Writes the end-of-archive marker (two zero blocks) and commits.
	 *
	 * @return int Final size of the underlying file.
	 */
	public function finish(): int {
		$this->sink->write( str_repeat( "\0", TarHeader::BLOCK_SIZE * 2 ) );
		return $this->sink->commit();
	}

	/**
	 * Non-fatal problems met while writing.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Zero padding after $size bytes of data.
	 *
	 * @param int $size Data size.
	 * @return void
	 */
	private function write_padding( int $size ): void {
		$pad = TarHeader::padding( $size );
		if ( $pad > 0 ) {
			$this->sink->write( str_repeat( "\0", $pad ) );
		}
	}
}
