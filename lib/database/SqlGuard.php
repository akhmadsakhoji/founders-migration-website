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

defined( 'ABSPATH' ) || exit;

/**
 * Decides which statements from an archive may run, and points them at other table names.
 *
 * Archives are untrusted. Only the statements a dump needs are allowed
 * (format v1, section 6): SET for session settings, DROP TABLE, CREATE TABLE,
 * INSERT, plus DROP/CREATE VIEW and TRIGGER for the deferred objects file.
 * Anything that reads other data (SELECT inside CREATE or INSERT), touches
 * the file system (DATA DIRECTORY, INTO OUTFILE, LOAD DATA), opens remote
 * connections (FEDERATED, CONNECT engines) or manages users is refused.
 */
final class SqlGuard {

	const ALLOWED_ENGINES = array( 'INNODB', 'MYISAM', 'ARIA', 'MEMORY' );
	const ALLOWED_SET     = '/^SET\s+(NAMES|FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|SQL_MODE|TIME_ZONE|CHARACTER_SET_CLIENT|SQL_NOTES)\b/i';
	const IDENTIFIER      = '(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';
	const SYSTEM_SCHEMAS  = '/(?:`|\b)(mysql|information_schema|performance_schema|sys)`?\s*\./i';

	/**
	 * Source table prefix (from the manifest).
	 *
	 * @var string
	 */
	private $from;

	/**
	 * Prefix the tables are created with.
	 *
	 * @var string
	 */
	private $to;

	/**
	 * Picks and renames tables: source name => name after the destination prefix, or null to leave the table out.
	 *
	 * @var callable|null
	 */
	private $rename;

	/**
	 * Constructor.
	 *
	 * @param string        $from   Source prefix, for example "wp_".
	 * @param string        $to     Destination prefix, for example "fmwtmp_".
	 * @param callable|null $rename Optional: source table => name after the destination prefix, or null to leave it out (default: the name after the source prefix).
	 */
	public function __construct( string $from, string $to, ?callable $rename = null ) {
		$this->from   = $from;
		$this->to     = $to;
		$this->rename = $rename;
	}

	/**
	 * Checks and rewrites one statement of a table dump.
	 *
	 * @param string $sql Statement without delimiter.
	 * @return array{kind:string,sql:string,table:string} kind is set, drop, create, insert or skip.
	 * @throws UnsafeSqlException When the statement is not allowed.
	 */
	public function table_statement( string $sql ): array {
		$code = self::code_only( $sql );

		if ( preg_match( self::ALLOWED_SET, $code ) && false === strpos( $code, ';' ) ) {
			return array(
				'kind'  => 'set',
				'sql'   => $sql,
				'table' => '',
			);
		}
		// The importer manages its own transactions (they carry the exactly-once progress record),
		// so transaction control and table locks from a dump are ignored.
		if ( preg_match( '/^(?:(?:LOCK|UNLOCK)\s+TABLES\b|START\s+TRANSACTION\b|BEGIN\s*(?:WORK\s*)?$|COMMIT\b|ROLLBACK\s*(?:WORK\s*)?$|SET\s+(?:@@(?:SESSION\.)?|SESSION\s+)?AUTOCOMMIT\s*=)/i', $code ) ) {
			return array(
				'kind'  => 'skip',
				'sql'   => $sql,
				'table' => '',
			);
		}

		if ( preg_match( '/^(DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?)' . self::IDENTIFIER . '\s*$/is', $sql, $m ) ) {
			return $this->renamed( 'drop', $sql, $m[1], $m[2] );
		}

		if ( preg_match( '/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)' . self::IDENTIFIER . '\s*\(/is', $sql, $m ) ) {
			if ( preg_match( '/\bSELECT\b|\bDATA\s+DIRECTORY\b|\bINDEX\s+DIRECTORY\b|\bCONNECTION\s*=|\bUNION\s*=|\bPARTITION\b|\b(LOAD_FILE|SLEEP|BENCHMARK|SYS_EXEC|SYS_EVAL)\s*\(/i', $code ) ) {
				throw new UnsafeSqlException( 'CREATE TABLE with SELECT, DATA/INDEX DIRECTORY, CONNECTION, UNION or PARTITION is not allowed.' );
			}
			preg_match_all( '/\bENGINE\s*=?\s*([A-Za-z0-9_]+)/i', $code, $engines );
			foreach ( $engines[1] as $engine ) {
				if ( ! in_array( strtoupper( $engine ), self::ALLOWED_ENGINES, true ) ) {
					throw new UnsafeSqlException( sprintf( 'Storage engine %s is not allowed.', $engine ) );
				}
			}
			$result = $this->renamed( 'create', $sql, $m[1], $m[2] );
			if ( 'skip' !== $result['kind'] ) {
				$result['sql'] = $this->rewrite_references( self::strip_constraint_names( $result['sql'] ) );
			}
			return $result;
		}

		if ( preg_match( '/^(INSERT\s+(?:IGNORE\s+)?INTO\s+)' . self::IDENTIFIER . '/is', $sql, $m ) ) {
			self::assert_literal_values( $code );
			return $this->renamed( 'insert', $sql, $m[1], $m[2] );
		}

		throw new UnsafeSqlException( sprintf( 'Statement not allowed in a backup: %s', self::excerpt( $sql ) ) );
	}

	/**
	 * Checks and rewrites one statement of the deferred views / triggers file.
	 *
	 * Every identifier starting with the source prefix outside string
	 * literals is moved to the destination prefix.
	 *
	 * @param string $sql Statement without delimiter.
	 * @return string
	 * @throws UnsafeSqlException When the statement is not allowed.
	 */
	public function object_statement( string $sql ): string {
		$code = self::code_only( $sql );
		if ( preg_match( self::ALLOWED_SET, $code ) ) {
			return $sql;
		}
		$allowed = '/^(DROP\s+(VIEW|TRIGGER)\s+(IF\s+EXISTS\s+)?|CREATE\s+(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(SQL\s+SECURITY\s+(DEFINER|INVOKER)\s+)?VIEW\s|CREATE\s+TRIGGER\s)/i';
		if ( ! preg_match( $allowed, $code ) ) {
			throw new UnsafeSqlException( sprintf( 'Statement not allowed in a backup: %s', self::excerpt( $sql ) ) );
		}
		if ( preg_match( self::SYSTEM_SCHEMAS, $code ) || preg_match( '/\bINTO\s+(OUT|DUMP)FILE\b|\bLOAD_FILE\s*\(|\bDEFINER\s*=/i', $code ) ) {
			throw new UnsafeSqlException( sprintf( 'View or trigger touches system schemas or files: %s', self::excerpt( $sql ) ) );
		}
		return $this->rewrite_identifiers( $sql );
	}

	/**
	 * Whether a statement creates or drops a view or trigger (to be run after the swap).
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	public static function is_object_statement( string $sql ): bool {
		try {
			$code = self::code_only( $sql );
		} catch ( UnsafeSqlException $e ) {
			return false; // table_statement() reports it.
		}
		return 1 === preg_match( '/^(DROP\s+(VIEW|TRIGGER)\b|CREATE\s+(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(DEFINER\s*=\s*\S+\s+)?(SQL\s+SECURITY\s+\w+\s+)?(VIEW|TRIGGER)\b)/i', $code );
	}

	/**
	 * Destination name for a source table, or null when it does not use the source prefix.
	 *
	 * @param string $table Source table name.
	 * @return string|null
	 * @throws UnsafeSqlException When the new name is longer than MySQL allows.
	 */
	public function map_table( string $table ): ?string {
		if ( '' !== $this->from && 0 !== strpos( $table, $this->from ) ) {
			return null;
		}
		$rest = null === $this->rename ? substr( $table, strlen( $this->from ) ) : ( $this->rename )( $table );
		if ( ! is_string( $rest ) || '' === $rest ) {
			return null;
		}
		$mapped = $this->to . $rest;
		if ( strlen( $mapped ) > 64 ) {
			throw new UnsafeSqlException( sprintf( 'Table name %s would be longer than 64 characters.', $mapped ) );
		}
		return $mapped;
	}

	/**
	 * The statement with string literals and quoted identifiers emptied, for keyword checks.
	 *
	 * A plain scanner rather than a regular expression: it runs in linear time
	 * on multi-megabyte INSERTs and cannot fail open on a regex limit.
	 *
	 * @param string $sql Statement.
	 * @return string
	 * @throws UnsafeSqlException On an unterminated quote.
	 */
	public static function code_only( string $sql ): string {
		$out    = '';
		$i      = 0;
		$length = strlen( $sql );
		while ( $i < $length ) {
			$j    = $i + strcspn( $sql, "'\"`", $i );
			$out .= substr( $sql, $i, $j - $i );
			if ( $j >= $length ) {
				break;
			}
			$quote = $sql[ $j ];
			$stops = '`' === $quote ? '`' : $quote . '\\';
			$k     = $j + 1;
			while ( true ) {
				$k += strcspn( $sql, $stops, $k );
				if ( $k >= $length ) {
					throw new UnsafeSqlException( 'Unterminated quoted string.' );
				}
				if ( '\\' === $sql[ $k ] ) {
					$k += 2;
					continue;
				}
				if ( $k + 1 < $length && $quote === $sql[ $k + 1 ] ) {
					$k += 2;
					continue;
				}
				break;
			}
			$out .= $quote . $quote;
			$i    = $k + 1;
		}
		return $out;
	}

	/**
	 * Requires the VALUES part of an INSERT to contain literals only.
	 *
	 * Dumps never need expressions there; allowing them would let an archive
	 * run functions such as LOAD_FILE() or subqueries during a restore.
	 *
	 * @param string $code INSERT statement from code_only().
	 * @return void
	 * @throws UnsafeSqlException When anything but literals follows VALUES.
	 */
	private static function assert_literal_values( string $code ): void {
		if ( ! preg_match( '/\bVALUES?\b/i', $code, $m, PREG_OFFSET_CAPTURE ) ) {
			throw new UnsafeSqlException( 'INSERT without VALUES is not allowed.' );
		}
		$values = substr( $code, (int) $m[0][1] + strlen( $m[0][0] ) );
		$rest   = preg_replace(
			'/\s+|[(),]|\bNULL\b|\bTRUE\b|\bFALSE\b|\bDEFAULT\b|0x[0-9a-f]*|[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?|\b[xb]\'\'|(?:_[a-z0-9]+\s*)?\'\'/i',
			'',
			$values
		);
		if ( null === $rest ) {
			throw new UnsafeSqlException( 'INSERT values could not be checked.' );
		}
		if ( '' !== $rest ) {
			throw new UnsafeSqlException( sprintf( 'INSERT values must be literals; found "%s".', substr( $rest, 0, 40 ) ) );
		}
	}

	/**
	 * Builds a result with the table identifier replaced.
	 *
	 * @param string $kind       Statement kind.
	 * @param string $sql        Statement.
	 * @param string $head       Text before the identifier.
	 * @param string $identifier Identifier as written.
	 * @return array{kind:string,sql:string,table:string}
	 */
	private function renamed( string $kind, string $sql, string $head, string $identifier ): array {
		$table  = self::unquote( $identifier );
		$mapped = $this->map_table( $table );
		if ( null === $mapped ) {
			return array(
				'kind'  => 'skip',
				'sql'   => $sql,
				'table' => $table,
			);
		}
		return array(
			'kind'  => $kind,
			'sql'   => $head . Connection::identifier( $mapped ) . substr( $sql, strlen( $head ) + strlen( $identifier ) ),
			'table' => $table,
		);
	}

	/**
	 * Points REFERENCES clauses of a CREATE TABLE at the destination prefix.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string
	 */
	private function rewrite_references( string $sql ): string {
		return (string) preg_replace_callback(
			'/(\bREFERENCES\s+)' . self::IDENTIFIER . '/i',
			function ( array $m ): string {
				$mapped = $this->map_table( self::unquote( $m[2] ) );
				return null === $mapped ? $m[0] : $m[1] . Connection::identifier( $mapped );
			},
			$sql
		);
	}

	/**
	 * Removes foreign key constraint names; they must be unique per database
	 * and would clash with the live tables during the restore.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string
	 */
	private static function strip_constraint_names( string $sql ): string {
		return (string) preg_replace( '/\bCONSTRAINT\s+' . self::IDENTIFIER . '\s+(FOREIGN\s+KEY)/i', '$2', $sql );
	}

	/**
	 * Moves identifiers with the source prefix to the destination prefix, outside string literals.
	 *
	 * @param string $sql Statement.
	 * @return string
	 * @throws UnsafeSqlException When the statement cannot be parsed.
	 */
	private function rewrite_identifiers( string $sql ): string {
		if ( '' === $this->from ) {
			return $sql;
		}
		$pieces = preg_split( "/('(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.|\"\")*\")/s", $sql, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $pieces ) {
			throw new UnsafeSqlException( 'Statement could not be parsed.' );
		}
		$out = '';
		foreach ( $pieces as $i => $piece ) {
			if ( 1 === $i % 2 ) {
				$out .= $piece; // String literal: untouched.
				continue;
			}
			$quoted_from = preg_quote( $this->from, '/' );
			$piece       = (string) preg_replace( '/`' . $quoted_from . '((?:[^`]|``)*)`/', '`' . str_replace( '\\', '\\\\', $this->to ) . '$1`', (string) $piece );
			$piece       = (string) preg_replace( '/(?<![A-Za-z0-9_$`])' . $quoted_from . '([A-Za-z0-9_$]+)/', str_replace( '\\', '\\\\', $this->to ) . '$1', $piece );
			$out        .= $piece;
		}
		return $out;
	}

	/**
	 * Identifier without backticks.
	 *
	 * @param string $identifier Identifier as written.
	 * @return string
	 */
	private static function unquote( string $identifier ): string {
		if ( '`' === $identifier[0] ) {
			return str_replace( '``', '`', substr( $identifier, 1, -1 ) );
		}
		return $identifier;
	}

	/**
	 * Short excerpt for messages.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	private static function excerpt( string $sql ): string {
		$sql = (string) preg_replace( '/\s+/', ' ', $sql );
		return strlen( $sql ) > 120 ? substr( $sql, 0, 120 ) . '…' : $sql;
	}
}
