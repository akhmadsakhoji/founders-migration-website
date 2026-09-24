<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Export;

use Founders\Migration\Archive\ArchiveException;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Append-only list with byte offsets needs a native handle.

/**
 * The scan result: one JSON object per line (NDJSON), read and written by byte offset.
 *
 * Keeping the list on disk instead of in memory lets a site with millions of
 * files be scanned and packed with constant memory, and a byte offset is all
 * a job needs to resume.
 *
 * Entry keys: p (path), t (f = file, d = folder, l = symlink), s (size),
 * m (mtime), o (permission bits), l (link target).
 */
final class FileList {

	/**
	 * List file.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Open handle.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Constructor.
	 *
	 * @param string $path List file.
	 */
	public function __construct( string $path ) {
		$this->path = $path;
	}

	/**
	 * Opens for appending, discarding anything written after $offset.
	 *
	 * @param int $offset Committed length.
	 * @return void
	 * @throws ArchiveException When the list cannot be opened or is shorter than $offset.
	 */
	public function open_for_append( int $offset ): void {
		$handle = fopen( $this->path, 'c+b' );
		if ( false === $handle ) {
			throw new ArchiveException( sprintf( 'Cannot open %s.', $this->path ) );
		}
		$stat = fstat( $handle );
		if ( false === $stat || $stat['size'] < $offset || ! ftruncate( $handle, $offset ) || 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			throw new ArchiveException( sprintf( 'Cannot resume %s at byte %d.', $this->path, $offset ) );
		}
		$this->handle = $handle;
	}

	/**
	 * Appends an entry.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @return void
	 * @throws ArchiveException On a failed write.
	 */
	public function append( array $entry ): void {
		if ( null === $this->handle ) {
			throw new ArchiveException( 'File list is not open.' );
		}
		$line = json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $line || false === fwrite( $this->handle, $line . "\n" ) ) {
			throw new ArchiveException( 'Cannot write the file list. The disk may be full.' );
		}
	}

	/**
	 * Flushes and returns the committed length.
	 *
	 * @return int
	 */
	public function commit(): int {
		if ( null === $this->handle ) {
			return is_file( $this->path ) ? (int) filesize( $this->path ) : 0;
		}
		fflush( $this->handle );
		if ( function_exists( 'fsync' ) ) {
			fsync( $this->handle ); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.fsyncFound -- Guarded by function_exists().
		}
		return (int) ftell( $this->handle );
	}

	/**
	 * Closes the handle.
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
	 * Opens for reading at $offset.
	 *
	 * @param int $offset Byte offset of the next line.
	 * @return void
	 * @throws ArchiveException When the list cannot be opened.
	 */
	public function open_for_read( int $offset ): void {
		$handle = fopen( $this->path, 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, $offset ) ) {
			throw new ArchiveException( sprintf( 'Cannot read %s at byte %d.', $this->path, $offset ) );
		}
		$this->handle = $handle;
	}

	/**
	 * Reads the next entry.
	 *
	 * @return array{0:array<string,mixed>,1:int}|null Entry and the offset after it, or null at the end.
	 * @throws ArchiveException On a corrupt line.
	 */
	public function next(): ?array {
		if ( null === $this->handle ) {
			throw new ArchiveException( 'File list is not open.' );
		}
		$line = fgets( $this->handle );
		if ( false === $line || '' === $line ) {
			return null;
		}
		$entry = json_decode( $line, true );
		if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['t'] ) ) {
			throw new ArchiveException( 'Corrupt file list entry.' );
		}
		return array( $entry, (int) ftell( $this->handle ) );
	}
}
