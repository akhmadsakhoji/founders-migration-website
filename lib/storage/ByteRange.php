<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Storage;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * HTTP Range header for downloads (one range; anything else gets the whole file).
 */
final class ByteRange {

	/**
	 * Parses "bytes=a-b", "bytes=a-" or "bytes=-n".
	 *
	 * @param string $header Range header ('' when absent).
	 * @param int    $size   File size.
	 * @return array{0:int,1:int}|null|false First and last byte; null for the whole file; false when unsatisfiable.
	 */
	public static function parse( string $header, int $size ) {
		if ( '' === $header || $size <= 0 ) {
			return null;
		}
		if ( 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header, " \t" ), $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return null; // Multiple or malformed ranges: send the whole file.
		}
		if ( '' === $m[1] ) {
			$length = min( (int) $m[2], $size );
			return 0 === $length ? false : array( $size - $length, $size - 1 );
		}
		$start = (int) $m[1];
		$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
		if ( $start >= $size || $start > $end ) {
			return false;
		}
		return array( $start, $end );
	}
}
