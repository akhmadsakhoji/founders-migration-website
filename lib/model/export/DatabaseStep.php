<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Export;

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\GzipFileSink;
use Founders\Migration\Database\Connection;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection; $wpdb->prepare() is not available on this connection.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Job folders are written with native calls, like the archive library.

/**
 * Dumps the site's tables as plain SQL: database/NNNN-<table>.CCCC.sql.gz.
 *
 * - Only tables with the site's table prefix (all subsites on multisite).
 * - Rows are read in primary-key order with keyset pagination
 *   (WHERE key > last), which stays fast on tables with millions of rows.
 *   Tables without a usable key fall back to LIMIT/OFFSET.
 * - Each chunk file is a multi-member gzip stream resumed at its last
 *   committed offset, so a dump continues in the middle of a table.
 * - Binary values are hex literals, generated columns are left out, and
 *   views and triggers are dumped without DEFINER clauses.
 * - Within one process all reads share one consistent snapshot
 *   (InnoDB). A resumed dump starts a new snapshot.
 *
 * Reads job options: exclude_database, table_prefix, exclude_tables, exclude (spam-comments,
 * post-revisions, transients), sql_chunk_bytes, batch_rows.
 * Writes job data: parts (database part records), database (totals).
 */
final class DatabaseStep implements Step {

	const DIR                 = 'database';
	const DEFAULT_CHUNK_BYTES = 268435456;
	const DEFAULT_BATCH_ROWS  = 1000;
	const STATEMENT_BYTES     = 1000000;
	const HEADER              = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";

	const NUMERIC_TYPES = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year' );
	const BINARY_TYPES  = array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' );

	/**
	 * Connection kept for the whole process so all slices share one snapshot.
	 *
	 * @var Connection|null
	 */
	private $db = null;

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Database';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the dump folder cannot be created.
	 */
	public function run( Job $job, Context $context ): bool {
		if ( ! empty( $job->options['exclude_database'] ) ) {
			$context->log( 'Database excluded.' );
			return true;
		}

		$db  = $this->connection();
		$dir = $context->dir() . '/' . self::DIR;
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new JobException( sprintf( 'Cannot create %s.', $dir ) );
		}

		$cursor = $job->cursor;
		if ( ! isset( $cursor['plan'] ) ) {
			$cursor = $this->plan( $db, $job->options, $context );

			$job->data['db_server'] = array(
				'version' => $db->server_version(),
				'charset' => 'utf8mb4',
			);
			$job->bytes_done        = 0;
			$job->bytes_total       = (int) $cursor['estimate'];
		}

		$parts       = isset( $job->data['parts'] ) ? (array) $job->data['parts'] : array();
		$chunk_bytes = max( 1, (int) ( $job->options['sql_chunk_bytes'] ?? self::DEFAULT_CHUNK_BYTES ) );
		$batch_rows  = max( 1, (int) ( $job->options['batch_rows'] ?? self::DEFAULT_BATCH_ROWS ) );
		$sink        = null;
		$items       = count( $cursor['plan'] );

		try {
			while ( $cursor['t'] < $items && $context->should_continue() ) {
				$item = $cursor['plan'][ $cursor['t'] ];
				$path = $dir . '/' . self::file_name( $cursor['t'] + 1, $item['name'], $cursor['chunk'] );

				if ( null === $sink ) {
					$sink = new GzipFileSink( $path, (int) $cursor['at'] );
					if ( 0 === (int) $cursor['at'] ) {
						$head           = self::HEADER . ( 1 === $cursor['chunk'] ? $this->preamble( $db, $item ) : '' );
						$cursor['raw'] += strlen( $head );
						$sink->write( $head );
					}
				}

				$table_done = true;
				if ( 'table' === $item['kind'] ) {
					list( $rows, $bytes )  = $this->dump_batch( $db, $item, $cursor, $sink, $batch_rows );
					$cursor['rows']       += $rows;
					$cursor['raw']        += $bytes;
					$cursor['total_rows'] += $rows;
					$job->bytes_done      += $bytes;
					$context->report_progress();
					$table_done = $rows < $batch_rows;
				}

				if ( $table_done || $cursor['raw'] >= $chunk_bytes ) {
					$sink->close();
					$sink    = null;
					$parts[] = self::part_record( $path, $item, $cursor );

					$cursor['total_raw'] += $cursor['raw'];
					$cursor['at']         = 0;
					$cursor['raw']        = 0;
					$cursor['rows']       = 0;
					if ( $table_done ) {
						++$cursor['t'];
						$cursor['chunk']  = 1;
						$cursor['last']   = null;
						$cursor['offset'] = 0;
					} else {
						++$cursor['chunk'];
					}
				}
			}

			if ( null !== $sink ) {
				$cursor['at'] = $sink->commit();
			}
		} finally {
			if ( null !== $sink ) {
				$sink->close();
			}
		}

		$job->data['parts'] = $parts;

		if ( $cursor['t'] < $items ) {
			$job->cursor = $cursor;
			return false;
		}

		$tables                = count(
			array_filter(
				$cursor['plan'],
				static function ( array $item ) {
					return 'table' === $item['kind'];
				}
			)
		);
		$job->data['database'] = array(
			'tables'    => $tables,
			'rows'      => (int) $cursor['total_rows'],
			'bytes_raw' => (int) $cursor['total_raw'],
		);
		$job->bytes_done       = $job->bytes_total;
		$context->log( sprintf( 'Dumped %d tables, %d rows, %d bytes of SQL.', $tables, $cursor['total_rows'], $cursor['total_raw'] ) );
		return true;
	}

	/**
	 * Opens the connection and its consistent snapshot, once per process.
	 *
	 * @return Connection
	 */
	private function connection(): Connection {
		if ( null === $this->db ) {
			$this->db = Connection::open();
			$this->db->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
			$this->db->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		}
		return $this->db;
	}

	/**
	 * Builds the dump plan: tables, then views, then triggers.
	 *
	 * @param Connection          $db      Connection.
	 * @param array<string,mixed> $options Job options.
	 * @param Context             $context Context.
	 * @return array<string,mixed> Fresh cursor.
	 */
	private function plan( Connection $db, array $options, Context $context ): array {
		$prefix  = (string) ( $options['table_prefix'] ?? '' );
		$exclude = array_fill_keys( array_map( 'strval', (array) ( $options['exclude_tables'] ?? array() ) ), true );
		$flags   = array_fill_keys( array_map( 'strval', (array) ( $options['exclude'] ?? array() ) ), true );
		$like    = $db->escape( str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $prefix ) ) . '%';

		$objects = $db->rows( "SELECT TABLE_NAME AS name, TABLE_TYPE AS type, COALESCE(DATA_LENGTH, 0) AS size FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$like}' ORDER BY TABLE_NAME" );

		$plan     = array();
		$views    = array();
		$estimate = 0;
		foreach ( $objects as $object ) {
			$name = (string) $object['name'];
			if ( isset( $exclude[ $name ] ) ) {
				continue;
			}
			if ( 'VIEW' === $object['type'] ) {
				$views[] = $name;
				continue;
			}
			if ( 'BASE TABLE' !== $object['type'] ) {
				continue;
			}

			$columns = array();
			foreach ( $db->rows( 'SELECT COLUMN_NAME AS name, LOWER(DATA_TYPE) AS type, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( $name ) . ' ORDER BY ORDINAL_POSITION' ) as $column ) {
				if ( preg_match( '/\b(VIRTUAL|STORED|PERSISTENT)\b/i', (string) $column['extra'] ) ) {
					continue; // Generated columns are recomputed by the server.
				}
				$columns[] = array(
					'n' => (string) $column['name'],
					'k' => self::value_kind( (string) $column['type'] ),
				);
			}

			$key = $this->key_columns( $db, $name );
			if ( array_diff( $key, array_column( $columns, 'n' ) ) ) {
				$key = array(); // A key on a generated column cannot be used for paging.
			}
			if ( ! $key ) {
				$context->log( sprintf( 'Table %s has no primary or unique key; it is read with LIMIT/OFFSET.', $name ) );
			}

			$plan[]    = array(
				'kind'    => 'table',
				'name'    => $name,
				'columns' => $columns,
				'key'     => $key,
				'where'   => self::row_filter( $name, $prefix, $flags ),
			);
			$estimate += (int) $object['size'];
		}

		if ( $views ) {
			$plan[] = array(
				'kind'  => 'views',
				'name'  => 'views',
				'names' => $views,
			);
		}

		$tables = array_column(
			array_filter(
				$plan,
				static function ( array $item ) {
					return 'table' === $item['kind'];
				}
			),
			'name'
		);
		if ( $tables ) {
			$quoted   = implode( ',', array_map( array( $db, 'quote' ), $tables ) );
			$triggers = $db->column( "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ({$quoted}) ORDER BY TRIGGER_NAME" );
			if ( $triggers ) {
				$plan[] = array(
					'kind'  => 'triggers',
					'name'  => 'triggers',
					'names' => array_map( 'strval', $triggers ),
				);
			}
		}

		$routines = (int) ( $db->column( 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()' )[0] ?? 0 );
		if ( $routines > 0 ) {
			$context->log( sprintf( 'The database has %d stored procedures or functions; they are not included in format v1.', $routines ) );
		}

		$context->log( sprintf( 'Database plan: %d tables, %d views, prefix "%s", server %s.', count( $tables ), count( $views ), $prefix, $db->server_version() ) );

		return array(
			'plan'       => $plan,
			'estimate'   => $estimate,
			't'          => 0,
			'chunk'      => 1,
			'at'         => 0,
			'raw'        => 0,
			'rows'       => 0,
			'last'       => null,
			'offset'     => 0,
			'total_rows' => 0,
			'total_raw'  => 0,
		);
	}

	/**
	 * Columns of the primary key, else of a unique key over NOT NULL columns.
	 *
	 * @param Connection $db   Connection.
	 * @param string     $name Table.
	 * @return string[]
	 */
	private function key_columns( Connection $db, string $name ): array {
		$indexes = array();
		foreach ( $db->rows( 'SELECT INDEX_NAME AS idx, COLUMN_NAME AS col, NULLABLE AS nullable FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( $name ) . ' AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX' ) as $row ) {
			$indexes[ (string) $row['idx'] ][] = array( (string) $row['col'], 'YES' === $row['nullable'] );
		}
		if ( isset( $indexes['PRIMARY'] ) ) {
			return array_column( $indexes['PRIMARY'], 0 );
		}
		foreach ( $indexes as $columns ) {
			if ( ! in_array( true, array_column( $columns, 1 ), true ) ) {
				return array_column( $columns, 0 );
			}
		}
		return array();
	}

	/**
	 * Streams one batch of rows as INSERT statements.
	 *
	 * @param Connection          $db         Connection.
	 * @param array<string,mixed> $item       Plan item.
	 * @param array<string,mixed> $cursor     Cursor (last / offset are updated).
	 * @param GzipFileSink        $sink       Output.
	 * @param int                 $batch_rows Rows per batch.
	 * @return array{0:int,1:int} Rows and bytes written.
	 */
	private function dump_batch( Connection $db, array $item, array &$cursor, GzipFileSink $sink, int $batch_rows ): array {
		$columns = $item['columns'];
		$names   = array_column( $columns, 'n' );
		$select  = implode( ', ', array_map( array( Connection::class, 'identifier' ), $names ) );
		$read    = implode(
			', ',
			array_map(
				static function ( array $column ): string {
					$name = Connection::identifier( $column['n'] );
					// BIT values come back as raw bytes or as decimal text depending on the driver; read them as numbers.
					return 'bit' === $column['k'] ? "({$name} + 0) AS {$name}" : $name;
				},
				$columns
			)
		);
		$table   = Connection::identifier( $item['name'] );
		$where   = '' === $item['where'] ? array() : array( '(' . $item['where'] . ')' );
		$key     = $item['key'];
		$kinds   = array_combine( $names, array_column( $columns, 'k' ) );

		if ( $key ) {
			if ( null !== $cursor['last'] ) {
				$last_key = array_map( 'base64_decode', (array) $cursor['last'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key values may be binary; job state is JSON.
				$where[]  = $this->after_key( $db, $key, $last_key, $kinds );
			}
			$order = ' ORDER BY ' . implode( ', ', array_map( array( Connection::class, 'identifier' ), $key ) ) . " LIMIT {$batch_rows}";
		} else {
			$order = " LIMIT {$batch_rows} OFFSET " . (int) $cursor['offset'];
		}

		$sql    = "SELECT {$read} FROM {$table}" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . $order;
		$result = $db->query( $sql, true );
		if ( true === $result ) {
			return array( 0, 0 );
		}

		$insert    = "INSERT INTO {$table} (" . $select . ') VALUES ';
		$buffer    = '';
		$bytes     = 0;
		$rows      = 0;
		$key_index = array_map(
			static function ( string $column ) use ( $names ): int {
				return (int) array_search( $column, $names, true );
			},
			$key
		);
		$last      = null;

		try {
			$row = $result->fetch_row();
			while ( is_array( $row ) ) {
				$values = array();
				foreach ( $row as $i => $value ) {
					$values[] = $this->literal( $db, $value, $columns[ $i ]['k'] );
				}
				$tuple   = '(' . implode( ',', $values ) . ')';
				$buffer .= ( '' === $buffer ? '' : ',' ) . $tuple;
				if ( strlen( $buffer ) >= self::STATEMENT_BYTES ) {
					$statement = $insert . $buffer . ";\n";
					$sink->write( $statement );
					$bytes += strlen( $statement );
					$buffer = '';
				}
				++$rows;
				$last = $row;
				$row  = $result->fetch_row();
			}
		} finally {
			$result->free();
		}

		if ( '' !== $buffer ) {
			$statement = $insert . $buffer . ";\n";
			$sink->write( $statement );
			$bytes += strlen( $statement );
		}

		if ( null !== $last && $key ) {
			$cursor['last'] = array_map(
				static function ( int $index ) use ( $last ): string {
					return base64_encode( (string) $last[ $index ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key values may be binary; job state is JSON.
				},
				$key_index
			);
		}
		$cursor['offset'] += $rows;

		return array( $rows, $bytes );
	}

	/**
	 * Keyset condition "row comes after $values" for a (composite) key.
	 *
	 * Expanded as (a > x) OR (a = x AND b > y) so indexes are used on every server version.
	 *
	 * @param Connection           $db     Connection.
	 * @param string[]             $key    Key columns.
	 * @param string[]             $values Last key values.
	 * @param array<string,string> $kinds  Column => value kind.
	 * @return string
	 */
	private function after_key( Connection $db, array $key, array $values, array $kinds ): string {
		$or = array();
		foreach ( $key as $i => $column ) {
			$and = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$and[] = Connection::identifier( $key[ $j ] ) . ' = ' . $this->literal( $db, $values[ $j ], $kinds[ $key[ $j ] ] ?? 's' );
			}
			$and[] = Connection::identifier( $column ) . ' > ' . $this->literal( $db, $values[ $i ], $kinds[ $column ] ?? 's' );
			$or[]  = '(' . implode( ' AND ', $and ) . ')';
		}
		return '(' . implode( ' OR ', $or ) . ')';
	}

	/**
	 * SQL literal for a value.
	 *
	 * @param Connection  $db    Connection.
	 * @param string|null $value Value as returned by mysqli.
	 * @param string      $kind  n = numeric, bit = BIT as a number, b = binary, s = string.
	 * @return string
	 */
	private function literal( Connection $db, ?string $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( ( 'n' === $kind || 'bit' === $kind ) && is_numeric( $value ) ) {
			return $value;
		}
		if ( 'b' === $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return $db->quote( $value );
	}

	/**
	 * DROP + CREATE for a table, or the whole contents of the views / triggers file.
	 *
	 * @param Connection          $db   Connection.
	 * @param array<string,mixed> $item Plan item.
	 * @return string
	 */
	private function preamble( Connection $db, array $item ): string {
		if ( 'table' === $item['kind'] ) {
			$table  = Connection::identifier( $item['name'] );
			$create = (string) ( $db->rows( "SHOW CREATE TABLE {$table}" )[0]['Create Table'] ?? '' );
			return "\nDROP TABLE IF EXISTS {$table};\n" . $create . ";\n\n";
		}

		$sql = "\n";
		if ( 'views' === $item['kind'] ) {
			foreach ( $item['names'] as $name ) {
				$view   = Connection::identifier( $name );
				$create = (string) ( $db->rows( "SHOW CREATE VIEW {$view}" )[0]['Create View'] ?? '' );
				$sql   .= "DROP VIEW IF EXISTS {$view};\n" . self::strip_definer( $create ) . ";\n";
			}
			return $sql;
		}

		$sql .= "DELIMITER ;;\n";
		foreach ( $item['names'] as $name ) {
			$trigger = Connection::identifier( $name );
			$create  = (string) ( $db->rows( "SHOW CREATE TRIGGER {$trigger}" )[0]['SQL Original Statement'] ?? '' );
			$sql    .= "DROP TRIGGER IF EXISTS {$trigger};;\n" . self::strip_definer( $create ) . ";;\n";
		}
		return $sql . "DELIMITER ;\n";
	}

	/**
	 * Removes DEFINER=`user`@`host` so the object can be created by any user.
	 *
	 * @param string $sql CREATE statement.
	 * @return string
	 */
	public static function strip_definer( string $sql ): string {
		return (string) preg_replace( '/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|[^\s]+)/i', '', $sql );
	}

	/**
	 * Value kind for a column type: n = numeric (unquoted), bit = BIT read as a number, b = binary (hex), s = string.
	 *
	 * @param string $type DATA_TYPE, lowercase.
	 * @return string
	 */
	private static function value_kind( string $type ): string {
		if ( 'bit' === $type ) {
			return 'bit';
		}
		if ( in_array( $type, self::NUMERIC_TYPES, true ) ) {
			return 'n';
		}
		return in_array( $type, self::BINARY_TYPES, true ) ? 'b' : 's';
	}

	/**
	 * WHERE clause for the ai1wm-style row exclusions, or ''.
	 *
	 * Works for subsite tables too (wp_2_comments).
	 *
	 * @param string             $table  Table name.
	 * @param string             $prefix Site table prefix.
	 * @param array<string,bool> $flags  Enabled exclusions.
	 * @return string
	 */
	public static function row_filter( string $table, string $prefix, array $flags ): string {
		if ( '' !== $prefix && 0 !== strpos( $table, $prefix ) ) {
			return '';
		}
		$base        = substr( $table, strlen( $prefix ) );
		$site_prefix = $prefix;
		if ( preg_match( '/^(\d+_)(.+)$/', $base, $m ) ) {
			$site_prefix .= $m[1];
			$base         = $m[2];
		}

		$comments = Connection::identifier( $site_prefix . 'comments' );
		$posts    = Connection::identifier( $site_prefix . 'posts' );
		$filters  = array();

		if ( isset( $flags['spam-comments'] ) ) {
			if ( 'comments' === $base ) {
				$filters[] = "`comment_approved` <> 'spam'";
			} elseif ( 'commentmeta' === $base ) {
				$filters[] = "`comment_id` NOT IN (SELECT `comment_ID` FROM {$comments} WHERE `comment_approved` = 'spam')";
			}
		}
		if ( isset( $flags['post-revisions'] ) ) {
			if ( 'posts' === $base ) {
				$filters[] = "`post_type` <> 'revision'";
			} elseif ( 'postmeta' === $base ) {
				$filters[] = "`post_id` NOT IN (SELECT `ID` FROM {$posts} WHERE `post_type` = 'revision')";
			}
		}
		if ( isset( $flags['transients'] ) ) {
			if ( 'options' === $base ) {
				$filters[] = "`option_name` NOT LIKE '\\_transient\\_%' AND `option_name` NOT LIKE '\\_site\\_transient\\_%'";
			} elseif ( 'sitemeta' === $base ) {
				$filters[] = "`meta_key` NOT LIKE '\\_site\\_transient\\_%'";
			}
		}

		return implode( ' AND ', $filters );
	}

	/**
	 * Chunk file name: NNNN-<table>.CCCC.sql.gz, relative to the database folder.
	 *
	 * @param int    $order Restore order (1-based).
	 * @param string $name  Table name (or "views" / "triggers").
	 * @param int    $chunk Chunk number (1-based).
	 * @return string
	 */
	public static function file_name( int $order, string $name, int $chunk ): string {
		return sprintf( '%04d-%s.%04d.sql.gz', $order, preg_replace( '/[^A-Za-z0-9_$-]/', '_', $name ), $chunk );
	}

	/**
	 * Manifest record for a finished chunk.
	 *
	 * @param string              $path   Absolute chunk path.
	 * @param array<string,mixed> $item   Plan item.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array<string,mixed>
	 * @throws ArchiveException When the file cannot be hashed.
	 */
	private static function part_record( string $path, array $item, array $cursor ): array {
		$hash = hash_file( 'sha256', $path );
		if ( false === $hash ) {
			throw new ArchiveException( sprintf( 'Cannot hash %s.', $path ) );
		}
		return array(
			'path'        => self::DIR . '/' . basename( $path ),
			'type'        => 'database',
			'table'       => 'table' === $item['kind'] ? $item['name'] : $item['kind'],
			'chunk'       => (int) $cursor['chunk'],
			'rows'        => (int) $cursor['rows'],
			'compression' => 'gzip',
			'bytes_raw'   => (int) $cursor['raw'],
			'bytes'       => (int) filesize( $path ),
			'sha256'      => $hash,
		);
	}
}
