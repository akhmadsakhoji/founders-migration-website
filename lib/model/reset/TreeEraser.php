<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Reset;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Deletes the contents of a folder, in time slices, except the paths to keep.
 *
 * The folder itself stays. Symbolic links are removed, never followed, so
 * nothing outside the folder is ever deleted. Deleting is idempotent, so a
 * slice simply walks the folder again from the top: what is gone stays gone.
 * A file that cannot be deleted stops the job with its path (fix the
 * permissions, then resume) instead of being skipped silently.
 */
final class TreeEraser {

	/**
	 * Folder to empty.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Absolute paths to keep (with everything below them).
	 *
	 * @var string[]
	 */
	private $keep;

	/**
	 * Constructor.
	 *
	 * @param string   $root Folder to empty.
	 * @param string[] $keep Absolute paths to keep.
	 */
	public function __construct( string $root, array $keep ) {
		$this->root = self::normalize( $root );
		$this->keep = array_values( array_unique( array_map( array( self::class, 'normalize' ), $keep ) ) );
	}

	/**
	 * Deletes until done or until the slice is over.
	 *
	 * @param callable(): bool $should_continue Asked after each deletion.
	 * @param int              $deleted         Incremented per deleted file, link or folder.
	 * @return bool True when nothing is left to delete.
	 * @throws \RuntimeException When something cannot be deleted.
	 */
	public function erase( callable $should_continue, int &$deleted ): bool {
		if ( '' === $this->root || ! is_dir( $this->root ) ) {
			return true;
		}

		$keep     = $this->keep;
		$root     = $this->root;
		$filter   = static function ( \SplFileInfo $item ) use ( $keep ): bool {
			return ! in_array( self::normalize( $item->getPathname() ), $keep, true );
		};
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), $filter ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = self::normalize( $item->getPathname() );
			if ( ! $item->isLink() && $item->isDir() ) {
				if ( $this->holds_kept( $path ) ) {
					continue;
				}
				if ( ! @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plain PHP (jobs run without WP_Filesystem); reported below.
					throw new \RuntimeException( sprintf( 'Cannot delete the folder %s (check its permissions).', $path ) );
				}
			} elseif ( ! @unlink( $path ) && ! ( is_dir( $path ) && @rmdir( $path ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A link to a folder needs rmdir on Windows.
				throw new \RuntimeException( sprintf( 'Cannot delete %s (check its permissions).', $path ) );
			}
			++$deleted;
			if ( ! $should_continue() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a folder contains a kept path.
	 *
	 * @param string $path Folder.
	 * @return bool
	 */
	private function holds_kept( string $path ): bool {
		foreach ( $this->keep as $kept ) {
			if ( 0 === strpos( $kept, $path . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Forward slashes, no trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalize( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}
}
