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
 * Validates archive paths before anything touches the disk ("zip slip" protection).
 *
 * Archives coming from outside are untrusted: entry names and link targets
 * must stay inside the extraction root.
 */
final class PathGuard {

	/**
	 * Normalises an entry name into a safe relative path.
	 *
	 * Rejects NUL bytes, backslashes, absolute paths, drive letters and any
	 * ".." segment. Empty and "." segments are dropped.
	 *
	 * @param string $name Entry name from the archive.
	 * @return string Relative path with forward slashes; '' for the root itself.
	 * @throws UnsafePathException On an unsafe name.
	 */
	public static function relative( string $name ): string {
		if ( false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) ) {
			throw new UnsafePathException( sprintf( 'Unsafe characters in archive path "%s".', self::printable( $name ) ) );
		}
		if ( '' !== $name && '/' === $name[0] ) {
			throw new UnsafePathException( sprintf( 'Absolute archive path "%s".', self::printable( $name ) ) );
		}
		if ( preg_match( '/^[A-Za-z]:/', $name ) ) {
			throw new UnsafePathException( sprintf( 'Drive letter in archive path "%s".', self::printable( $name ) ) );
		}

		$parts = array();
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				throw new UnsafePathException( sprintf( 'Parent directory reference in archive path "%s".', self::printable( $name ) ) );
			}
			$parts[] = $segment;
		}

		return implode( '/', $parts );
	}

	/**
	 * Checks that a symlink stored at $relative with target $target stays inside the root.
	 *
	 * @param string $relative Safe relative path of the link (from relative()).
	 * @param string $target   Link target from the archive.
	 * @return void
	 * @throws UnsafePathException When the target is absolute or escapes the root.
	 */
	public static function assert_symlink_target( string $relative, string $target ): void {
		if ( '' === $target || false !== strpos( $target, "\0" ) || false !== strpos( $target, '\\' ) ) {
			throw new UnsafePathException( sprintf( 'Invalid symlink target for "%s".', self::printable( $relative ) ) );
		}
		if ( '/' === $target[0] || preg_match( '/^[A-Za-z]:/', $target ) ) {
			throw new UnsafePathException( sprintf( 'Absolute symlink target for "%s".', self::printable( $relative ) ) );
		}

		$stack = explode( '/', $relative );
		array_pop( $stack ); // The link's own name.

		foreach ( explode( '/', $target ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( ! $stack ) {
					throw new UnsafePathException( sprintf( 'Symlink "%s" points outside the extraction root.', self::printable( $relative ) ) );
				}
				array_pop( $stack );
				continue;
			}
			$stack[] = $segment;
		}
	}

	/**
	 * Printable form of a path for error messages.
	 *
	 * @param string $value Path.
	 * @return string
	 */
	private static function printable( string $value ): string {
		return (string) preg_replace( '/[^\x20-\x7E]/', '?', $value );
	}
}
