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
 * Safely extracts a TAR stream into a directory.
 *
 * - Entry names and symlink targets are validated by PathGuard.
 * - Nothing is ever written through a symlink: an entry whose parent path
 *   contains a symlink is rejected, and an existing symlink at the target
 *   path is removed before a file is written.
 * - Hard links and special files are skipped with a warning.
 */
final class Extractor {

	/**
	 * Extraction root, no trailing slash.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Non-fatal problems met while extracting.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param string $root Existing or creatable directory.
	 * @throws ArchiveException When the root cannot be created.
	 */
	public function __construct( string $root ) {
		$root = rtrim( $root, '/' );
		if ( ! is_dir( $root ) && ! mkdir( $root, 0755, true ) && ! is_dir( $root ) ) {
			throw new ArchiveException( sprintf( 'Cannot create %s.', $root ) );
		}
		$this->root = $root;
	}

	/**
	 * Extracts every remaining entry of $reader.
	 *
	 * @param TarReader $reader Reader.
	 * @return int Number of entries written.
	 */
	public function extract_all( TarReader $reader ): int {
		$count = 0;
		$entry = $reader->next();
		while ( null !== $entry ) {
			if ( $this->extract_entry( $reader, $entry ) ) {
				++$count;
			}
			$entry = $reader->next();
		}
		return $count;
	}

	/**
	 * Extracts the current entry of $reader.
	 *
	 * @param TarReader $reader Reader positioned on $entry.
	 * @param TarEntry  $entry  Entry returned by $reader->next().
	 * @return bool Whether something was written.
	 * @throws ArchiveException On an I/O failure or an unsafe entry.
	 */
	public function extract_entry( TarReader $reader, TarEntry $entry ): bool {
		$relative = PathGuard::relative( $entry->name );
		if ( '' === $relative ) {
			return false;
		}

		$this->assert_no_symlink_parents( $relative );
		$target = $this->root . '/' . $relative;

		switch ( $entry->type ) {
			case TarEntry::TYPE_DIRECTORY:
				if ( is_link( $target ) ) {
					unlink( $target );
				}
				$this->ensure_directory( $target, $entry->mode );
				return true;

			case TarEntry::TYPE_FILE:
				$this->ensure_directory( dirname( $target ), 0755 );
				if ( is_link( $target ) ) {
					unlink( $target );
				}
				$handle = fopen( $target, 'wb' );
				if ( false === $handle ) {
					throw new ArchiveException( sprintf( 'Cannot write %s.', $target ) );
				}
				$reader->copy_to( $handle );
				fclose( $handle );
				chmod( $target, ( $entry->mode & 0777 ) | 0600 );
				if ( $entry->mtime > 0 ) {
					touch( $target, $entry->mtime );
				}
				return true;

			case TarEntry::TYPE_SYMLINK:
				PathGuard::assert_symlink_target( $relative, $entry->linkname );
				$this->ensure_directory( dirname( $target ), 0755 );
				if ( is_link( $target ) || is_file( $target ) ) {
					unlink( $target );
				}
				if ( ! symlink( $entry->linkname, $target ) ) {
					$this->warnings[] = sprintf( 'Could not create symlink %s.', $relative );
					return false;
				}
				return true;

			default:
				$this->warnings[] = sprintf( 'Skipped unsupported entry type "%s" at %s.', $entry->type, $relative );
				return false;
		}
	}

	/**
	 * Non-fatal problems met while extracting.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Rejects paths whose existing parent directories include a symlink.
	 *
	 * @param string $relative Safe relative path.
	 * @return void
	 * @throws UnsafePathException When a parent is a symlink.
	 */
	private function assert_no_symlink_parents( string $relative ): void {
		$parts = explode( '/', $relative );
		array_pop( $parts );
		$path = $this->root;
		foreach ( $parts as $part ) {
			$path .= '/' . $part;
			if ( is_link( $path ) ) {
				throw new UnsafePathException( sprintf( 'Refusing to write through symlink %s.', substr( $path, strlen( $this->root ) + 1 ) ) );
			}
			if ( ! file_exists( $path ) ) {
				return;
			}
		}
	}

	/**
	 * Creates a directory (and parents) if missing.
	 *
	 * @param string $path Directory.
	 * @param int    $mode Permission bits.
	 * @return void
	 * @throws ArchiveException When it cannot be created.
	 */
	private function ensure_directory( string $path, int $mode ): void {
		if ( is_dir( $path ) ) {
			return;
		}
		if ( ! mkdir( $path, ( $mode & 0777 ) | 0700, true ) && ! is_dir( $path ) ) {
			throw new ArchiveException( sprintf( 'Cannot create %s.', $path ) );
		}
	}
}
