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
 * Metadata of one TAR entry.
 */
final class TarEntry {

	const TYPE_FILE      = '0';
	const TYPE_HARDLINK  = '1';
	const TYPE_SYMLINK   = '2';
	const TYPE_DIRECTORY = '5';

	/**
	 * Path inside the archive, forward slashes, no leading slash.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * One of the TYPE_* constants (other typeflags are passed through as-is when reading).
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Size of the entry data in bytes.
	 *
	 * @var int
	 */
	public $size;

	/**
	 * Permission bits.
	 *
	 * @var int
	 */
	public $mode;

	/**
	 * Modification time, Unix timestamp.
	 *
	 * @var int
	 */
	public $mtime;

	/**
	 * Link target for symlinks and hard links.
	 *
	 * @var string
	 */
	public $linkname;

	/**
	 * Constructor.
	 *
	 * @param string $name     Path inside the archive.
	 * @param string $type     Typeflag.
	 * @param int    $size     Data size in bytes.
	 * @param int    $mode     Permission bits.
	 * @param int    $mtime    Modification time.
	 * @param string $linkname Link target.
	 */
	public function __construct( string $name, string $type = self::TYPE_FILE, int $size = 0, int $mode = 0644, int $mtime = 0, string $linkname = '' ) {
		$this->name     = $name;
		$this->type     = $type;
		$this->size     = $size;
		$this->mode     = $mode;
		$this->mtime    = $mtime;
		$this->linkname = $linkname;
	}

	/**
	 * Regular file entry.
	 *
	 * @param string $name  Path inside the archive.
	 * @param int    $size  Data size in bytes.
	 * @param int    $mode  Permission bits.
	 * @param int    $mtime Modification time.
	 * @return self
	 */
	public static function file( string $name, int $size, int $mode = 0644, int $mtime = 0 ): self {
		return new self( $name, self::TYPE_FILE, $size, $mode, $mtime );
	}

	/**
	 * Directory entry.
	 *
	 * @param string $name  Path inside the archive.
	 * @param int    $mode  Permission bits.
	 * @param int    $mtime Modification time.
	 * @return self
	 */
	public static function directory( string $name, int $mode = 0755, int $mtime = 0 ): self {
		return new self( $name, self::TYPE_DIRECTORY, 0, $mode, $mtime );
	}

	/**
	 * Symbolic link entry.
	 *
	 * @param string $name   Path inside the archive.
	 * @param string $target Link target.
	 * @param int    $mtime  Modification time.
	 * @return self
	 */
	public static function symlink( string $name, string $target, int $mtime = 0 ): self {
		return new self( $name, self::TYPE_SYMLINK, 0, 0777, $mtime, $target );
	}

	/**
	 * Whether this entry carries file data.
	 *
	 * @return bool
	 */
	public function is_file(): bool {
		return self::TYPE_FILE === $this->type;
	}

	/**
	 * Whether this entry is a directory.
	 *
	 * @return bool
	 */
	public function is_directory(): bool {
		return self::TYPE_DIRECTORY === $this->type;
	}

	/**
	 * Whether this entry is a symbolic link.
	 *
	 * @return bool
	 */
	public function is_symlink(): bool {
		return self::TYPE_SYMLINK === $this->type;
	}
}
