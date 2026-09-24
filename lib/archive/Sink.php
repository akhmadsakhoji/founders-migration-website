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
 * Destination of archive bytes with explicit durability points.
 */
interface Sink {

	/**
	 * Appends bytes.
	 *
	 * @param string $bytes Bytes.
	 * @return void
	 */
	public function write( string $bytes ): void;

	/**
	 * Makes everything written so far durable and self-contained, and returns
	 * the byte offset in the underlying file. A job may later resume by
	 * reopening the sink at exactly this offset.
	 *
	 * @return int
	 */
	public function commit(): int;

	/**
	 * Commits and releases the file handle.
	 *
	 * @return void
	 */
	public function close(): void;
}
