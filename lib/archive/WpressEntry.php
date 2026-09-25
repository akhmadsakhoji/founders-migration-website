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

/**
 * One file stored in an All-in-One WP Migration (.wpress) archive.
 */
final class WpressEntry {

	/**
	 * Path relative to wp-content ("package.json", "uploads/2026/a.jpg"), as stored.
	 *
	 * Not yet checked: pass it through PathGuard::relative() before using it.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Stored data size in bytes (after encryption / compression, if any).
	 *
	 * @var int
	 */
	public $size;

	/**
	 * Modification time (Unix seconds).
	 *
	 * @var int
	 */
	public $mtime;

	/**
	 * Byte offset of the entry's header in the archive.
	 *
	 * @var int
	 */
	public $offset;

	/**
	 * CRC-32 (lowercase hex) of the original content; '' in archives without CRCs.
	 *
	 * @var string
	 */
	public $crc32;

	/**
	 * Constructor.
	 *
	 * @param string $name   Path.
	 * @param int    $size   Data size.
	 * @param int    $mtime  Modification time.
	 * @param int    $offset Header offset.
	 * @param string $crc32  CRC-32 hex or ''.
	 */
	public function __construct( string $name, int $size, int $mtime, int $offset, string $crc32 = '' ) {
		$this->name   = $name;
		$this->size   = $size;
		$this->mtime  = $mtime;
		$this->offset = $offset;
		$this->crc32  = $crc32;
	}

	/**
	 * Offset of the first data byte.
	 *
	 * @return int
	 */
	public function data_offset(): int {
		return $this->offset + WpressReader::HEADER_BYTES;
	}
}
