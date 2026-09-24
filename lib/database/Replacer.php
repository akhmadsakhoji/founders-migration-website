<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Database;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Search-replace that keeps PHP serialized data valid.
 *
 * Serialized strings are rewritten by walking the serialization format
 * directly and recomputing every s:<length>: prefix, never with unserialize():
 * calling unserialize() on data from an archive could instantiate arbitrary
 * classes (PHP object injection). Strings nested inside serialized strings
 * (double serialization) are handled recursively. Custom-serialized objects
 * (C:) are opaque and left as they are.
 *
 * Replacement is a single pass with strtr(): text produced by one pair is
 * never matched again, so "example.com" -> "example.com/staging" is safe.
 */
final class Replacer {

	/**
	 * Search => replace.
	 *
	 * @var array<string,string>
	 */
	private $pairs;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $pairs Search => replace. Empty searches are ignored.
	 */
	public function __construct( array $pairs ) {
		unset( $pairs[''] );
		$this->pairs = $pairs;
	}

	/**
	 * Whether there is nothing to replace.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return ! $this->pairs;
	}

	/**
	 * Search strings, for pre-filtering rows with LIKE.
	 *
	 * @return string[]
	 */
	public function needles(): array {
		return array_map( 'strval', array_keys( $this->pairs ) );
	}

	/**
	 * Replaces in a value, serialized or not.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function replace( string $value ): string {
		if ( ! $this->pairs || ! $this->contains_needle( $value ) ) {
			return $value;
		}
		if ( self::looks_serialized( $value ) ) {
			$offset = 0;
			$result = $this->walk( $value, $offset, 0 );
			if ( null !== $result && strlen( $value ) === $offset ) {
				return $result;
			}
		}
		return strtr( $value, $this->pairs );
	}

	/**
	 * URL, path and email pairs for moving a site (format v1, section 6).
	 *
	 * For each URL: the exact value, the other scheme, protocol-relative,
	 * JSON-escaped and URL-encoded forms. Nothing is added when old and new match.
	 *
	 * @param array<string,string> $urls        Old URL => new URL (home and site URL).
	 * @param array<string,string> $paths       Old path => new path.
	 * @param bool                 $email_hosts Also replace "@old-host" with "@new-host".
	 * @return array<string,string>
	 */
	public static function site_pairs( array $urls, array $paths, bool $email_hosts ): array {
		$pairs = array();
		foreach ( $urls as $old => $new ) {
			$old = rtrim( (string) $old, '/' );
			$new = rtrim( (string) $new, '/' );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$old_bare = (string) preg_replace( '#^https?:#i', '', $old ); // "//example.com".
			$new_bare = (string) preg_replace( '#^https?:#i', '', $new );

			foreach ( array( 'http:', 'https:' ) as $scheme ) {
				$pairs[ $scheme . $old_bare ] = $new;
			}
			$pairs[ $old_bare ] = $new_bare;

			foreach ( array( 'http:', 'https:' ) as $scheme ) {
				$pairs[ str_replace( '/', '\\/', $scheme . $old_bare ) ] = str_replace( '/', '\\/', $new );
				$pairs[ rawurlencode( $scheme . $old_bare ) ]            = rawurlencode( $new );
			}
			$pairs[ str_replace( '/', '\\/', $old_bare ) ] = str_replace( '/', '\\/', $new_bare );

			if ( $email_hosts ) {
				$old_host = self::host( $old );
				$new_host = self::host( $new );
				if ( '' !== $old_host && '' !== $new_host && $old_host !== $new_host ) {
					$pairs[ '@' . $old_host ] = '@' . $new_host;
				}
			}
		}
		foreach ( $paths as $old => $new ) {
			$old = rtrim( (string) $old, '/' );
			$new = rtrim( (string) $new, '/' );
			if ( '' !== $old && $old !== $new ) {
				$pairs[ $old ]                            = $new;
				$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
			}
		}
		return $pairs;
	}

	/**
	 * Host of a URL.
	 *
	 * @param string $url URL, possibly protocol-relative.
	 * @return string
	 */
	private static function host( string $url ): string {
		$host = parse_url( ( 0 === strpos( $url, '//' ) ? 'http:' : '' ) . $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Library code must not depend on WordPress.
		return is_string( $host ) ? $host : '';
	}

	/**
	 * Whether any search string occurs in $value.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function contains_needle( string $value ): bool {
		foreach ( $this->pairs as $search => $replace ) {
			if ( false !== strpos( $value, (string) $search ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cheap check for serialized data (same shapes as WordPress' is_serialized()).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function looks_serialized( string $value ): bool {
		$value = trim( $value, " \n\r\t\v\0" );
		if ( strlen( $value ) < 4 || ':' !== $value[1] ) {
			return 'N;' === $value;
		}
		$last = substr( $value, -1 );
		return in_array( $value[0], array( 's', 'a', 'O', 'i', 'd', 'b', 'C', 'E' ), true ) && ( ';' === $last || '}' === $last );
	}

	/**
	 * Rewrites one serialized value starting at $offset.
	 *
	 * @param string $data   Serialized data.
	 * @param int    $offset Position; advanced past the value.
	 * @param int    $depth  Nesting depth (guards against hostile input).
	 * @param bool   $rewrite Whether strings are rewritten (false for array keys and property names).
	 * @return string|null Rewritten value, or null when the data is not valid serialization.
	 */
	private function walk( string $data, int &$offset, int $depth, bool $rewrite = true ): ?string {
		if ( $depth > 256 || ! isset( $data[ $offset ] ) ) {
			return null;
		}
		$type = $data[ $offset ];

		switch ( $type ) {
			case 'N':
				if ( 'N;' !== substr( $data, $offset, 2 ) ) {
					return null;
				}
				$offset += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				if ( ! preg_match( '/\G' . $type . ':[^;]*;/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$offset += strlen( $m[0] );
				return $m[0];

			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$length = (int) $m[1];
				$start  = $offset + strlen( $m[0] );
				if ( '";' !== substr( $data, $start + $length, 2 ) ) {
					return null;
				}
				$string  = substr( $data, $start, $length );
				$offset  = $start + $length + 2;
				$changed = $rewrite ? $this->replace( $string ) : $string; // replace() handles nested serialization too.
				return 's:' . strlen( $changed ) . ':"' . $changed . '";';

			case 'E':
				if ( ! preg_match( '/\GE:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$end = $offset + strlen( $m[0] ) + (int) $m[1];
				if ( '";' !== substr( $data, $end, 2 ) ) {
					return null;
				}
				$enum   = substr( $data, $offset, $end + 2 - $offset );
				$offset = $end + 2;
				return $enum;

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$offset += strlen( $m[0] );
				return $this->walk_members( $data, $offset, $depth, (int) $m[1], $m[0] );

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$name_start = $offset + strlen( $m[0] );
				$name_end   = $name_start + (int) $m[1];
				if ( ! preg_match( '/\G":(\d+):\{/', $data, $n, 0, $name_end ) ) {
					return null;
				}
				$head   = substr( $data, $offset, $name_end - $offset ) . $n[0];
				$offset = $name_end + strlen( $n[0] );
				return $this->walk_members( $data, $offset, $depth, (int) $n[1], $head );

			case 'C':
				if ( ! preg_match( '/\GC:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$name_end = $offset + strlen( $m[0] ) + (int) $m[1];
				if ( ! preg_match( '/\G":(\d+):\{/', $data, $n, 0, $name_end ) ) {
					return null;
				}
				$end = $name_end + strlen( $n[0] ) + (int) $n[1];
				if ( '}' !== ( $data[ $end ] ?? '' ) ) {
					return null;
				}
				$custom = substr( $data, $offset, $end + 1 - $offset );
				$offset = $end + 1;
				return $custom; // Opaque payload: left untouched.
		}

		return null;
	}

	/**
	 * Rewrites the key/value pairs of an array or object and its closing brace.
	 *
	 * @param string $data   Serialized data.
	 * @param int    $offset Position after "{"; advanced past "}".
	 * @param int    $depth  Nesting depth.
	 * @param int    $count  Number of members.
	 * @param string $head   Text up to and including "{".
	 * @return string|null
	 */
	private function walk_members( string $data, int &$offset, int $depth, int $count, string $head ): ?string {
		$out = $head;
		for ( $i = 0; $i < $count * 2; $i++ ) {
			$item = $this->walk( $data, $offset, $depth + 1, 1 === $i % 2 ); // Keys stay as they are.
			if ( null === $item ) {
				return null;
			}
			$out .= $item;
		}
		if ( '}' !== ( $data[ $offset ] ?? '' ) ) {
			return null;
		}
		++$offset;
		return $out . '}';
	}
}
