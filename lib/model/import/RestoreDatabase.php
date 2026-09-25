<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Import;

use Founders\Migration\Database\Connection;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection.

/**
 * Shared database helpers for the restore steps.
 *
 * Tables are imported as fmwtmp_<name>, swapped in with one atomic RENAME
 * TABLE, and the live tables they replace become fmwold_<name> until the
 * restore has succeeded. Progress that must change together with data
 * (imported statements, replaced rows) lives in the fmwtmp__progress table
 * and is updated in the same transaction, so every statement and every row
 * update runs exactly once even when a process dies between two checkpoints.
 */
final class RestoreDatabase {

	const TMP      = 'fmwtmp_';
	const OLD      = 'fmwold_';
	const PROGRESS = 'fmwtmp__progress';

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $db;

	/**
	 * Connects and applies the session settings every import needs.
	 */
	public function __construct() {
		$this->db = Connection::open();
		foreach ( array( 'SET NAMES utf8mb4', 'SET SESSION FOREIGN_KEY_CHECKS = 0', 'SET SESSION UNIQUE_CHECKS = 0', "SET SESSION SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'", 'SET SESSION autocommit = 1' ) as $sql ) {
			$this->db->query( $sql );
		}
	}

	/**
	 * Connection.
	 *
	 * @return Connection
	 */
	public function db(): Connection {
		return $this->db;
	}

	/**
	 * Creates the progress table.
	 *
	 * @return void
	 */
	public function create_progress(): void {
		$this->db->query( 'CREATE TABLE IF NOT EXISTS ' . Connection::identifier( self::PROGRESS ) . ' (`k` varchar(191) NOT NULL PRIMARY KEY, `v` longtext NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
	}

	/**
	 * Reads a progress value.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	public function progress( string $key ): ?string {
		$rows = $this->db->column( 'SELECT `v` FROM ' . Connection::identifier( self::PROGRESS ) . ' WHERE `k` = ' . $this->db->quote( $key ) );
		return isset( $rows[0] ) ? (string) $rows[0] : null;
	}

	/**
	 * Writes a progress value (inside the caller's transaction, if any).
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return void
	 */
	public function set_progress( string $key, string $value ): void {
		$this->db->query( 'INSERT INTO ' . Connection::identifier( self::PROGRESS ) . ' (`k`, `v`) VALUES (' . $this->db->quote( $key ) . ', ' . $this->db->quote( $value ) . ') ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)' );
	}

	/**
	 * Base tables (or views) whose names start with $prefix, sorted.
	 *
	 * @param string $prefix Prefix.
	 * @param string $type   BASE TABLE or VIEW.
	 * @return string[]
	 */
	public function tables( string $prefix, string $type = 'BASE TABLE' ): array {
		$like = $this->db->escape( str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $prefix ) ) . '%';
		return array_map(
			'strval',
			$this->db->column( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = {$this->db->quote( $type )} AND TABLE_NAME LIKE '{$like}' ORDER BY TABLE_NAME" )
		);
	}

	/**
	 * Tables of this site: those starting with $prefix, minus those of other
	 * WordPress installs sharing the database with a longer prefix (wp_shop_
	 * when this site is wp_), recognised by their own <prefix>options table.
	 * When unsure, a table is left out: better kept than dropped. The
	 * fmwtmp_* and fmwold_* tables of restores never count.
	 *
	 * @param string $prefix Site table prefix.
	 * @param string $type   BASE TABLE or VIEW.
	 * @return string[]
	 */
	public function site_tables( string $prefix, string $type = 'BASE TABLE' ): array {
		$all     = array_values(
			array_filter(
				$this->tables( $prefix, $type ),
				static function ( string $table ): bool {
					return 0 !== strpos( $table, self::TMP ) && 0 !== strpos( $table, self::OLD );
				}
			)
		);
		$foreign = array();
		foreach ( 'BASE TABLE' === $type ? $all : $this->tables( $prefix ) as $table ) {
			if ( 'options' === substr( $table, -7 ) && $prefix . 'options' !== $table ) {
				$candidate = substr( $table, 0, -7 );
				if ( '_' === substr( $candidate, -1 ) ) {
					$foreign[] = $candidate;
				}
			}
		}
		return array_values(
			array_filter(
				$all,
				static function ( string $table ) use ( $foreign ): bool {
					foreach ( $foreign as $other ) {
						if ( 0 === strpos( $table, $other ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Imported tables (excluding the progress table).
	 *
	 * @return string[]
	 */
	public function imported_tables(): array {
		return array_values(
			array_filter(
				$this->tables( self::TMP ),
				static function ( string $table ): bool {
					return self::PROGRESS !== $table && SubsiteImport::USERMAP !== $table;
				}
			)
		);
	}

	/**
	 * Drops tables.
	 *
	 * @param string[] $tables Table names.
	 * @return void
	 */
	public function drop( array $tables ): void {
		foreach ( array_chunk( $tables, 50 ) as $chunk ) {
			$this->db->query( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( array( Connection::class, 'identifier' ), $chunk ) ) );
		}
	}

	/**
	 * Primary key columns of a table (empty when there is none).
	 *
	 * @param string $table Table.
	 * @return string[]
	 */
	public function primary_key( string $table ): array {
		return array_map(
			'strval',
			$this->db->column( "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = {$this->db->quote( $table )} AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX" )
		);
	}
}
