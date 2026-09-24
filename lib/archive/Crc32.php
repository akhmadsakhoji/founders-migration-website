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
 * CRC-32 (the "crc32b" of PHP's hash extension) helpers.
 *
 * The combine() method joins the CRCs of two consecutive pieces of data without reading
 * them again, so a checksum can be carried across processes: each slice of a
 * job hashes its own bytes and the results are combined. Same method as
 * zlib's crc32_combine() (GF(2) matrix powers); needs 64-bit integers.
 */
final class Crc32 {

	const POLYNOMIAL = 0xEDB88320;

	/**
	 * CRC-32 of A.B from crc(A), crc(B) and the length of B.
	 *
	 * @param string $crc_a  CRC of the first piece (hex).
	 * @param string $crc_b  CRC of the second piece (hex).
	 * @param int    $length Byte length of the second piece.
	 * @return string Lowercase hex, 8 characters.
	 */
	public static function combine( string $crc_a, string $crc_b, int $length ): string {
		$a = (int) hexdec( $crc_a );
		$b = (int) hexdec( $crc_b );
		if ( $length <= 0 ) {
			return sprintf( '%08x', $a );
		}

		// Operator that appends one zero bit, squared three times: one zero byte.
		$operator = array( self::POLYNOMIAL );
		for ( $n = 1, $row = 1; $n < 32; $n++, $row <<= 1 ) {
			$operator[ $n ] = $row;
		}
		$operator = self::square( self::square( self::square( $operator ) ) );

		// Append $length zero bytes to $a: one operator per set bit, squaring for the next bit.
		while ( $length > 0 ) {
			if ( $length & 1 ) {
				$a = self::times( $operator, $a );
			}
			$length >>= 1;
			if ( $length > 0 ) {
				$operator = self::square( $operator );
			}
		}

		return sprintf( '%08x', ( $a ^ $b ) & 0xFFFFFFFF );
	}

	/**
	 * Matrix times vector over GF(2).
	 *
	 * @param int[] $matrix 32 rows.
	 * @param int   $vector Vector.
	 * @return int
	 */
	private static function times( array $matrix, int $vector ): int {
		$sum = 0;
		for ( $i = 0; 0 !== $vector; $i++, $vector >>= 1 ) {
			if ( $vector & 1 ) {
				$sum ^= $matrix[ $i ];
			}
		}
		return $sum;
	}

	/**
	 * Matrix squared over GF(2).
	 *
	 * @param int[] $matrix 32 rows.
	 * @return int[]
	 */
	private static function square( array $matrix ): array {
		$square = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$square[ $n ] = self::times( $matrix, $matrix[ $n ] );
		}
		return $square;
	}
}
