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
 * Uncompressed file sink, used for parts holding already-compressed media.
 */
final class PlainFileSink extends FileSink {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bytes Bytes.
	 */
	public function write( string $bytes ): void {
		if ( '' !== $bytes ) {
			$this->write_raw( $bytes );
		}
	}
}
