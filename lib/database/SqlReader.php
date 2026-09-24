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

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB SQL files.

/**
 * Splits a (gzip-compressed, multi-member) SQL dump into statements without loading it into memory.
 *
 * Understands quoted strings and identifiers (backslash and doubled-quote
 * escapes), "--", "#" and block comments (dropped), and DELIMITER lines as
 * written by mysqldump and by FMW for triggers. Every statement comes with the
 * uncompressed byte offset right after it and the active delimiter, which is
 * all a restore needs to resume.
 */
final class SqlReader {

	const MAX_STATEMENT_BYTES = 67108864;
	const READ_BYTES          = 1048576;
	const WHITESPACE          = " \n\r\t\v\0";

	/**
	 * Stream.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Input not yet returned as a statement.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Absolute offset of $buffer[0] in the uncompressed stream.
	 *
	 * @var int
	 */
	private $base = 0;

	/**
	 * Whether the stream is exhausted.
	 *
	 * @var bool
	 */
	private $eof = false;

	/**
	 * Current statement delimiter.
	 *
	 * @var string
	 */
	private $delimiter;

	/**
	 * Opens a .sql or .sql.gz file.
	 *
	 * @param string $path      File path.
	 * @param int    $offset    Uncompressed offset to start at (from a previous next()).
	 * @param string $delimiter Delimiter active at $offset.
	 * @throws DatabaseException When the file cannot be read.
	 */
	public function __construct( string $path, int $offset = 0, string $delimiter = ';' ) {
		$probe = fopen( $path, 'rb' );
		if ( false === $probe ) {
			throw new DatabaseException( sprintf( 'Cannot open %s.', $path ) );
		}
		$magic = (string) fread( $probe, 2 );
		fclose( $probe );

		// compress.zlib:// reads every gzip member; gzdecode() would stop after the first.
		$handle = fopen( ( "\x1f\x8b" === $magic ? 'compress.zlib://' : '' ) . $path, 'rb' );
		if ( false === $handle ) {
			throw new DatabaseException( sprintf( 'Cannot open %s.', $path ) );
		}
		$this->handle    = $handle;
		$this->delimiter = '' === $delimiter ? ';' : $delimiter;

		while ( $this->base < $offset ) {
			$chunk = fread( $this->handle, (int) min( self::READ_BYTES, $offset - $this->base ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new DatabaseException( sprintf( '%s ends before byte %d.', $path, $offset ) );
			}
			$this->base += strlen( $chunk );
		}
	}

	/**
	 * Closes the stream.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * The next statement, without its delimiter and with comments removed.
	 *
	 * @return array{sql:string,offset:int,delimiter:string}|null Null at the end.
	 * @throws DatabaseException On an unterminated or oversized statement.
	 */
	public function next(): ?array {
		$sql     = '';
		$segment = 0;
		$pos     = 0;
		$started = false;

		while ( true ) {
			if ( ! $this->ensure( $pos + 1 ) ) {
				$rest = trim( $sql . substr( $this->buffer, $segment, $pos - $segment ), self::WHITESPACE );
				$this->consume( $pos );
				if ( '' !== $rest ) {
					throw new DatabaseException( 'The SQL file ends in the middle of a statement.' );
				}
				return null;
			}
			if ( strlen( $sql ) + $pos - $segment > self::MAX_STATEMENT_BYTES ) {
				throw new DatabaseException( 'A statement in the SQL file is larger than 64 MB.' );
			}

			$char = $this->buffer[ $pos ];

			if ( ! $started ) {
				if ( false !== strpos( self::WHITESPACE, $char ) ) {
					++$pos;
					$segment = $pos;
					continue;
				}
				$line_end = $this->line_end( $pos );
				$line     = rtrim( substr( $this->buffer, $pos, $line_end - $pos ), self::WHITESPACE );
				if ( 1 === preg_match( '/^DELIMITER[ \t]+(\S+)/i', $line, $m ) ) {
					$this->delimiter = $m[1];
					$pos             = $line_end;
					$segment         = $pos;
					continue;
				}
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$started = true;
				$pos     = $this->quote_end( $pos, $char );
				continue;
			}

			if ( $this->comment_starts( $pos ) ) {
				$sql    .= substr( $this->buffer, $segment, $pos - $segment ) . ( $started ? ' ' : '' );
				$pos     = $this->comment_end( $pos );
				$segment = $pos;
				continue;
			}

			$length = strlen( $this->delimiter );
			if ( $char === $this->delimiter[0] && $this->ensure( $pos + $length ) && substr( $this->buffer, $pos, $length ) === $this->delimiter ) {
				$sql .= substr( $this->buffer, $segment, $pos - $segment );
				$this->consume( $pos + $length );
				$sql = trim( $sql, self::WHITESPACE );
				if ( '' === $sql ) {
					return $this->next();
				}
				return array(
					'sql'       => $sql,
					'offset'    => $this->base,
					'delimiter' => $this->delimiter,
				);
			}

			$started = true;
			$pos    += 1 + strcspn( $this->buffer, "'\"`-#/" . $this->delimiter[0], $pos + 1 );
		}
	}

	/**
	 * Makes sure $bytes bytes are buffered (unless the stream ends first).
	 *
	 * @param int $bytes Needed buffer length.
	 * @return bool Whether they are available.
	 */
	private function ensure( int $bytes ): bool {
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The buffer grows each pass.
		while ( strlen( $this->buffer ) < $bytes && ! $this->eof && null !== $this->handle ) {
			$chunk = fread( $this->handle, self::READ_BYTES );
			if ( false === $chunk || '' === $chunk ) {
				$this->eof = true;
				break;
			}
			$this->buffer .= $chunk;
		}
		return strlen( $this->buffer ) >= $bytes;
	}

	/**
	 * Drops consumed input.
	 *
	 * @param int $bytes Consumed bytes.
	 * @return void
	 */
	private function consume( int $bytes ): void {
		$this->base  += $bytes;
		$this->buffer = (string) substr( $this->buffer, $bytes );
	}

	/**
	 * Position after the quoted string or identifier that opens at $pos.
	 *
	 * @param int    $pos   Opening quote.
	 * @param string $quote Quote character.
	 * @return int
	 * @throws DatabaseException When the quote never closes.
	 */
	private function quote_end( int $pos, string $quote ): int {
		$stops = '`' === $quote ? '`' : $quote . '\\';
		$i     = $pos + 1;
		while ( true ) {
			if ( ! $this->ensure( $i + 1 ) ) {
				throw new DatabaseException( 'Unterminated quoted string in the SQL file.' );
			}
			$i += strcspn( $this->buffer, $stops, $i );
			if ( $i >= strlen( $this->buffer ) ) {
				continue; // Read more and keep scanning.
			}
			if ( '\\' === $this->buffer[ $i ] ) {
				$i += 2;
				continue;
			}
			if ( $this->ensure( $i + 2 ) && $quote === $this->buffer[ $i + 1 ] ) {
				$i += 2; // Doubled quote.
				continue;
			}
			return $i + 1;
		}
	}

	/**
	 * Whether a comment starts at $pos.
	 *
	 * @param int $pos Position.
	 * @return bool
	 */
	private function comment_starts( int $pos ): bool {
		$char = $this->buffer[ $pos ];
		if ( '#' === $char ) {
			return true;
		}
		$this->ensure( $pos + 3 );
		$next = $this->buffer[ $pos + 1 ] ?? '';
		if ( '/' === $char ) {
			return '*' === $next;
		}
		if ( '-' === $char && '-' === $next ) {
			$third = $this->buffer[ $pos + 2 ] ?? "\n";
			return false !== strpos( " \t\r\n", $third );
		}
		return false;
	}

	/**
	 * Position after the comment that starts at $pos.
	 *
	 * @param int $pos Comment start.
	 * @return int
	 */
	private function comment_end( int $pos ): int {
		$block = '/' === $this->buffer[ $pos ];
		$from  = $pos + ( $block ? 2 : 1 );
		while ( true ) {
			$end = $block ? strpos( $this->buffer, '*/', $from ) : strpos( $this->buffer, "\n", $from );
			if ( false !== $end ) {
				return $end + ( $block ? 2 : 1 );
			}
			$from = max( $from, strlen( $this->buffer ) - 1 );
			if ( ! $this->ensure( strlen( $this->buffer ) + 1 ) ) {
				return strlen( $this->buffer );
			}
		}
	}

	/**
	 * Position after the line that starts at $pos (or the end of input).
	 *
	 * @param int $pos Line start.
	 * @return int
	 */
	private function line_end( int $pos ): int {
		$from = $pos;
		while ( true ) {
			$end = strpos( $this->buffer, "\n", $from );
			if ( false !== $end ) {
				return $end + 1;
			}
			$from = strlen( $this->buffer );
			if ( $from - $pos > 1024 || ! $this->ensure( $from + 1 ) ) {
				return strlen( $this->buffer );
			}
		}
	}
}
