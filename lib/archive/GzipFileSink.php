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

/**
 * Multi-member gzip sink.
 *
 * Every commit() closes the current gzip member. Concatenated members form a
 * valid gzip stream (RFC 1952, section 2.2), so `gzip -d` and `tar -xzf`
 * read the file as one stream, while a resumed job only has to truncate the
 * file at the last committed offset and start a new member.
 */
final class GzipFileSink extends FileSink {

	/**
	 * Active deflate context, null between members. PHP 7.4 returns a
	 * resource and PHP 8+ a DeflateContext object; both are opaque handles
	 * passed straight back to deflate_add().
	 *
	 * @var resource|null
	 */
	private $deflate = null;

	/**
	 * Compression level 1-9.
	 *
	 * @var int
	 */
	private $level;

	/**
	 * Constructor.
	 *
	 * @param string $path      File path.
	 * @param int    $resume_at Committed offset to resume from.
	 * @param int    $level     Compression level 1-9.
	 */
	public function __construct( string $path, int $resume_at = 0, int $level = 6 ) {
		parent::__construct( $path, $resume_at );
		$this->level = max( 1, min( 9, $level ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bytes Bytes.
	 * @throws ArchiveException When zlib cannot be initialised.
	 */
	public function write( string $bytes ): void {
		if ( '' === $bytes ) {
			return;
		}

		if ( null === $this->deflate ) {
			$context = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => $this->level ) );
			if ( false === $context ) {
				throw new ArchiveException( 'Cannot initialise gzip compression.' );
			}
			$this->deflate = $context;
		}

		$out = deflate_add( $this->deflate, $bytes, ZLIB_NO_FLUSH );
		if ( false === $out ) {
			throw new ArchiveException( 'gzip compression failed.' );
		}
		if ( '' !== $out ) {
			$this->write_raw( $out );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ArchiveException When the member cannot be finished.
	 */
	protected function finish_segment(): void {
		if ( null === $this->deflate ) {
			return;
		}

		$out = deflate_add( $this->deflate, '', ZLIB_FINISH );
		if ( false === $out ) {
			throw new ArchiveException( 'gzip compression failed.' );
		}
		$this->write_raw( $out );
		$this->deflate = null;
	}
}
