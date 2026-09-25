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

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming multi-GB files requires direct, seekable file handles; WP_Filesystem cannot do this.

/**
 * Sink backed by a local file, resumable at a committed offset.
 */
abstract class FileSink implements Sink {

	/**
	 * Open file handle, null once closed.
	 *
	 * @var resource|null
	 */
	protected $handle;

	/**
	 * File path.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Opens $path for writing. An existing file is truncated to $resume_at
	 * (anything after the last commit is discarded); a new file is created.
	 *
	 * @param string $path      File path.
	 * @param int    $resume_at Committed offset to resume from, 0 for a new file.
	 * @throws ArchiveException When the file cannot be opened or is shorter than $resume_at.
	 */
	public function __construct( string $path, int $resume_at = 0 ) {
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new ArchiveException( sprintf( 'Cannot open %s for writing.', $path ) );
		}

		$stat = fstat( $handle );
		$size = false === $stat ? 0 : (int) $stat['size'];
		if ( $resume_at < 0 || $resume_at > $size ) {
			fclose( $handle );
			throw new ArchiveException( sprintf( 'Cannot resume %s at byte %d: file is %d bytes.', $path, $resume_at, $size ) );
		}

		if ( ! ftruncate( $handle, $resume_at ) || 0 !== fseek( $handle, $resume_at ) ) {
			fclose( $handle );
			throw new ArchiveException( sprintf( 'Cannot position %s at byte %d.', $path, $resume_at ) );
		}

		$this->handle = $handle;
		$this->path   = $path;
	}

	/**
	 * File path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ArchiveException When the sink is closed.
	 */
	public function commit(): int {
		if ( null === $this->handle ) {
			throw new ArchiveException( 'Sink is closed.' );
		}

		$this->finish_segment();
		fflush( $this->handle );
		if ( function_exists( 'fsync' ) ) {
			fsync( $this->handle ); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.fsyncFound -- Guarded by function_exists().
		}

		return (int) ftell( $this->handle );
	}

	/**
	 * {@inheritDoc}
	 */
	public function close(): void {
		if ( null === $this->handle ) {
			return;
		}
		$this->commit();
		fclose( $this->handle );
		$this->handle = null;
	}

	/**
	 * Current byte offset in the file (written, not necessarily committed).
	 *
	 * @return int
	 * @throws ArchiveException When the sink is closed.
	 */
	public function position(): int {
		if ( null === $this->handle ) {
			throw new ArchiveException( 'Sink is closed.' );
		}
		return (int) ftell( $this->handle );
	}

	/**
	 * Hook for subclasses to close an open compression segment before a commit.
	 *
	 * @return void
	 */
	protected function finish_segment(): void {
	}

	/**
	 * Writes all bytes or fails loudly (a short write usually means a full disk).
	 *
	 * @param string $bytes Bytes.
	 * @return void
	 * @throws ArchiveException On a failed or short write.
	 */
	protected function write_raw( string $bytes ): void {
		if ( null === $this->handle ) {
			throw new ArchiveException( 'Sink is closed.' );
		}

		$length  = strlen( $bytes );
		$written = 0;
		while ( $written < $length ) {
			$result = fwrite( $this->handle, 0 === $written ? $bytes : substr( $bytes, $written ) );
			if ( false === $result || 0 === $result ) {
				throw new ArchiveException( 'Write failed. The disk may be full.' );
			}
			$written += $result;
		}
	}
}
