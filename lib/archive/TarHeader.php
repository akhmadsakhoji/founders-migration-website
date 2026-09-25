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
 * Builds and parses POSIX.1-2001 (PAX) TAR headers.
 *
 * Writing always produces a ustar header, preceded by a PAX extended header
 * ('x') when the path or link target is longer than 100 bytes or not plain
 * ASCII, or when the size does not fit the 11-digit octal field (8 GiB).
 * Owner fields are always written as uid/gid 0 with empty names so archives
 * do not leak server account names.
 */
final class TarHeader {

	const BLOCK_SIZE     = 512;
	const MAX_OCTAL_SIZE = 8589934591; // 077777777777.
	const MAX_META_SIZE  = 1048576;    // Upper bound for PAX / GNU metadata payloads.

	/**
	 * Header block(s) for an entry: optional PAX header + ustar header.
	 *
	 * @param TarEntry $entry Entry metadata.
	 * @return string Multiple of 512 bytes.
	 */
	public static function build( TarEntry $entry ): string {
		$name = $entry->name;
		if ( $entry->is_directory() && '/' !== substr( $name, -1 ) ) {
			$name .= '/';
		}

		$pax = array();
		if ( strlen( $name ) > 100 || ! self::is_ascii( $name ) ) {
			$pax['path'] = $name;
		}
		if ( '' !== $entry->linkname && ( strlen( $entry->linkname ) > 100 || ! self::is_ascii( $entry->linkname ) ) ) {
			$pax['linkpath'] = $entry->linkname;
		}
		if ( $entry->size > self::MAX_OCTAL_SIZE ) {
			$pax['size'] = (string) $entry->size;
		}

		$out = '';
		if ( $pax ) {
			$records  = self::pax_records( $pax );
			$pax_name = 'PaxHeader/' . self::ascii_fallback( basename( rtrim( $name, '/' ) ), 80 );
			$out     .= self::ustar( $pax_name, strlen( $records ), 0644, $entry->mtime, 'x', '' );
			$out     .= $records . str_repeat( "\0", self::padding( strlen( $records ) ) );
		}

		$size = $entry->size > self::MAX_OCTAL_SIZE ? 0 : $entry->size;
		$out .= self::ustar(
			self::ascii_fallback( $name, 100 ),
			$size,
			$entry->mode,
			$entry->mtime,
			$entry->type,
			self::ascii_fallback( $entry->linkname, 100 )
		);

		return $out;
	}

	/**
	 * Bytes of zero padding that follow $size bytes of data.
	 *
	 * @param int $size Data size.
	 * @return int
	 */
	public static function padding( int $size ): int {
		$rest = $size % self::BLOCK_SIZE;
		return 0 === $rest ? 0 : self::BLOCK_SIZE - $rest;
	}

	/**
	 * Encodes PAX records ("<len> <key>=<value>\n", where <len> counts itself).
	 *
	 * @param array<string,string> $fields Key/value pairs.
	 * @return string
	 */
	public static function pax_records( array $fields ): string {
		$out = '';
		foreach ( $fields as $key => $value ) {
			$payload = ' ' . $key . '=' . $value . "\n";
			$length  = strlen( $payload ) + strlen( (string) strlen( $payload ) );
			if ( strlen( (string) $length ) + strlen( $payload ) !== $length ) {
				$length = strlen( $payload ) + strlen( (string) $length );
			}
			$out .= $length . $payload;
		}
		return $out;
	}

	/**
	 * Decodes PAX records.
	 *
	 * @param string $data Raw PAX payload.
	 * @return array<string,string>
	 * @throws ArchiveException On malformed records.
	 */
	public static function parse_pax_records( string $data ): array {
		$fields = array();
		$offset = 0;
		$total  = strlen( $data );

		while ( $offset < $total ) {
			if ( "\0" === $data[ $offset ] ) {
				break;
			}
			$space = strpos( $data, ' ', $offset );
			if ( false === $space ) {
				throw new ArchiveException( 'Malformed PAX record.' );
			}
			$length = (int) substr( $data, $offset, $space - $offset );
			if ( $length <= 0 || $offset + $length > $total ) {
				throw new ArchiveException( 'Malformed PAX record length.' );
			}
			$record = substr( $data, $space + 1, $length - ( $space - $offset ) - 2 );
			$equals = strpos( $record, '=' );
			if ( false === $equals ) {
				throw new ArchiveException( 'Malformed PAX record: missing "=".' );
			}
			$fields[ substr( $record, 0, $equals ) ] = substr( $record, $equals + 1 );
			$offset                                 += $length;
		}

		return $fields;
	}

	/**
	 * Parses one 512-byte header block.
	 *
	 * @param string $block Header block.
	 * @return array{name:string,mode:int,size:int,mtime:int,type:string,linkname:string}|null Null for an all-zero block.
	 * @throws ArchiveException On a bad checksum or short block.
	 */
	public static function parse( string $block ): ?array {
		if ( self::BLOCK_SIZE !== strlen( $block ) ) {
			throw new ArchiveException( 'Short TAR header block.' );
		}
		if ( str_repeat( "\0", self::BLOCK_SIZE ) === $block ) {
			return null;
		}

		$stored   = self::parse_numeric( substr( $block, 148, 8 ) );
		$unsigned = 256; // Eight spaces in place of the checksum field.
		$signed   = 256;
		for ( $i = 0; $i < self::BLOCK_SIZE; $i++ ) {
			if ( $i >= 148 && $i < 156 ) {
				continue;
			}
			$byte      = ord( $block[ $i ] );
			$unsigned += $byte;
			$signed   += $byte > 127 ? $byte - 256 : $byte;
		}
		if ( $stored !== $unsigned && $stored !== $signed ) {
			throw new ArchiveException( 'TAR header checksum mismatch.' );
		}

		$name   = self::cstring( substr( $block, 0, 100 ) );
		$magic  = substr( $block, 257, 5 );
		$prefix = 'ustar' === $magic ? self::cstring( substr( $block, 345, 155 ) ) : '';
		if ( '' !== $prefix ) {
			$name = $prefix . '/' . $name;
		}

		$type = $block[156];
		if ( "\0" === $type || '7' === $type ) {
			$type = TarEntry::TYPE_FILE;
		}

		return array(
			'name'     => $name,
			'mode'     => self::parse_numeric( substr( $block, 100, 8 ) ) & 07777,
			'size'     => self::parse_numeric( substr( $block, 124, 12 ) ),
			'mtime'    => self::parse_numeric( substr( $block, 136, 12 ) ),
			'type'     => $type,
			'linkname' => self::cstring( substr( $block, 157, 100 ) ),
		);
	}

	/**
	 * Raw ustar header block.
	 *
	 * @param string $name     Name (max 100 bytes).
	 * @param int    $size     Size.
	 * @param int    $mode     Mode.
	 * @param int    $mtime    Modification time.
	 * @param string $type     Typeflag.
	 * @param string $linkname Link name (max 100 bytes).
	 * @return string
	 */
	private static function ustar( string $name, int $size, int $mode, int $mtime, string $type, string $linkname ): string {
		$header = pack(
			'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
			$name,
			sprintf( '%07o', $mode & 07777 ),
			'0000000',
			'0000000',
			sprintf( '%011o', $size ),
			sprintf( '%011o', max( 0, min( $mtime, self::MAX_OCTAL_SIZE ) ) ),
			str_repeat( ' ', 8 ),
			$type,
			$linkname,
			'ustar',
			'00',
			'',
			'',
			'0000000',
			'0000000',
			'',
			''
		);

		$sum = 0;
		for ( $i = 0; $i < self::BLOCK_SIZE; $i++ ) {
			$sum += ord( $header[ $i ] );
		}

		return substr_replace( $header, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}

	/**
	 * Parses an octal or GNU base-256 numeric field.
	 *
	 * @param string $field Raw field.
	 * @return int
	 */
	private static function parse_numeric( string $field ): int {
		if ( '' !== $field && ( ord( $field[0] ) & 0x80 ) ) {
			$value = ord( $field[0] ) & 0x7f;
			$len   = strlen( $field );
			for ( $i = 1; $i < $len; $i++ ) {
				$value = ( $value << 8 ) | ord( $field[ $i ] );
			}
			return $value;
		}

		$digits = trim( $field, "\0 " );
		return '' === $digits ? 0 : (int) octdec( $digits );
	}

	/**
	 * Text up to the first NUL byte.
	 *
	 * @param string $value Raw field.
	 * @return string
	 */
	private static function cstring( string $value ): string {
		$nul = strpos( $value, "\0" );
		return false === $nul ? $value : substr( $value, 0, $nul );
	}

	/**
	 * Whether a string contains printable ASCII only.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_ascii( string $value ): bool {
		return ! preg_match( '/[^\x20-\x7E]/', $value );
	}

	/**
	 * Printable-ASCII approximation used in the legacy ustar fields.
	 *
	 * @param string $value Value.
	 * @param int    $max   Maximum length in bytes.
	 * @return string
	 */
	private static function ascii_fallback( string $value, int $max ): string {
		return substr( (string) preg_replace( '/[^\x20-\x7E]/', '_', $value ), 0, $max );
	}
}
