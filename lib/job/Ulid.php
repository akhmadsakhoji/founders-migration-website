<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Job;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * ULID job identifiers: 26 characters, sortable by creation time, safe in paths.
 *
 * @see https://github.com/ulid/spec
 */
final class Ulid {

	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * New ULID.
	 *
	 * @param int|null $milliseconds Unix time in milliseconds; now when null.
	 * @return string
	 */
	public static function generate( ?int $milliseconds = null ): string {
		$time = null === $milliseconds ? (int) floor( microtime( true ) * 1000 ) : $milliseconds;

		$out = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$out  = self::ALPHABET[ $time % 32 ] . $out;
			$time = intdiv( $time, 32 );
		}

		$bits = '';
		foreach ( str_split( random_bytes( 10 ) ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ (int) bindec( $chunk ) ];
		}

		return $out;
	}

	/**
	 * Whether $id is a well-formed ULID. Used before an ID is ever turned into a path.
	 *
	 * @param string $id Candidate.
	 * @return bool
	 */
	public static function is_valid( string $id ): bool {
		return 1 === preg_match( '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $id );
	}
}
