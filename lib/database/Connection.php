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

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli, WordPress.DB.RestrictedFunctions -- Dumps need their own connection: a consistent-snapshot transaction and row-by-row reads that $wpdb cannot provide.

/**
 * A dedicated mysqli connection for dumping and importing.
 *
 * Credentials come from wp-config.php at run time and are never written
 * to job state. Tests and tools can swap the factory with set_factory().
 */
final class Connection {

	/**
	 * Replacement factory.
	 *
	 * @var callable(): Connection|null
	 */
	private static $factory = null;

	/**
	 * Connection.
	 *
	 * @var \mysqli
	 */
	private $mysqli;

	/**
	 * Database name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Connects.
	 *
	 * @param string      $host   Host name or IP.
	 * @param string      $user   User.
	 * @param string      $pass   Password.
	 * @param string      $name   Database.
	 * @param int|null    $port   Port.
	 * @param string|null $socket Unix socket.
	 * @throws DatabaseException When the connection fails.
	 */
	public function __construct( string $host, string $user, string $pass, string $name, ?int $port = null, ?string $socket = null ) {
		$mysqli = mysqli_init();
		if ( false === $mysqli ) {
			throw new DatabaseException( 'Cannot initialise mysqli.' );
		}

		try {
			$connected = @$mysqli->real_connect( $host, $user, $pass, $name, $port, $socket ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The error is reported through the exception below.
		} catch ( \mysqli_sql_exception $e ) {
			throw new DatabaseException( 'Database connection failed: ' . $e->getMessage() );
		}
		if ( ! $connected ) {
			throw new DatabaseException( 'Database connection failed: ' . $mysqli->connect_error );
		}

		$this->mysqli = $mysqli;
		$this->name   = $name;
		$this->mysqli->set_charset( 'utf8mb4' );
	}

	/**
	 * Connection for the current site (or the test factory).
	 *
	 * @return self
	 * @throws DatabaseException When the connection fails.
	 */
	public static function open(): self {
		if ( null !== self::$factory ) {
			return ( self::$factory )();
		}
		if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) ) {
			throw new DatabaseException( 'Database settings are not defined.' );
		}
		list( $host, $port, $socket ) = self::parse_host( (string) DB_HOST );
		return new self( $host, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, $port, $socket );
	}

	/**
	 * Replaces how open() connects (tests and tools). Pass null to restore the default.
	 *
	 * @param callable(): Connection|null $factory Factory.
	 * @return void
	 */
	public static function set_factory( ?callable $factory ): void {
		self::$factory = $factory;
	}

	/**
	 * Splits a WordPress DB_HOST value.
	 *
	 * Accepts "host", "host:3307", "host:/path/to.sock", "[::1]:3307" and "/path/to.sock".
	 *
	 * @param string $value DB_HOST.
	 * @return array{0:string,1:int|null,2:string|null} Host, port, socket.
	 */
	public static function parse_host( string $value ): array {
		$value = trim( $value, " \n\r\t\v\0" );
		if ( '' !== $value && '/' === $value[0] ) {
			return array( 'localhost', null, $value );
		}
		if ( preg_match( '/^\[([^\]]+)\](?::(\d+))?$/', $value, $m ) ) {
			return array( $m[1], isset( $m[2] ) ? (int) $m[2] : null, null );
		}
		if ( 1 === substr_count( $value, ':' ) ) {
			list( $host, $rest ) = explode( ':', $value, 2 );
			if ( ctype_digit( $rest ) ) {
				return array( $host, (int) $rest, null );
			}
			return array( $host, null, $rest );
		}
		return array( '' === $value ? 'localhost' : $value, null, null );
	}

	/**
	 * Runs a statement.
	 *
	 * @param string $sql      SQL.
	 * @param bool   $unbuffer Stream rows from the server instead of loading them all (read every row before the next query).
	 * @return \mysqli_result|true
	 * @throws DatabaseException On failure.
	 */
	public function query( string $sql, bool $unbuffer = false ) {
		try {
			$result = $this->mysqli->query( $sql, $unbuffer ? MYSQLI_USE_RESULT : MYSQLI_STORE_RESULT );
		} catch ( \mysqli_sql_exception $e ) {
			throw new DatabaseException( $e->getMessage() . ' [' . self::excerpt( $sql ) . ']' );
		}
		if ( false === $result ) {
			throw new DatabaseException( $this->mysqli->error . ' [' . self::excerpt( $sql ) . ']' );
		}
		return $result;
	}

	/**
	 * All rows of a query as associative arrays.
	 *
	 * @param string $sql SQL.
	 * @return array<int,array<string,string|null>>
	 */
	public function rows( string $sql ): array {
		$result = $this->query( $sql );
		if ( true === $result ) {
			return array();
		}
		$rows = $result->fetch_all( MYSQLI_ASSOC );
		$result->free();
		return $rows;
	}

	/**
	 * First column of every row.
	 *
	 * @param string $sql SQL.
	 * @return array<int,string|null>
	 */
	public function column( string $sql ): array {
		return array_map(
			static function ( array $row ) {
				return reset( $row );
			},
			$this->rows( $sql )
		);
	}

	/**
	 * Escapes a string for use inside single quotes.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function escape( string $value ): string {
		return $this->mysqli->real_escape_string( $value );
	}

	/**
	 * Quoted string literal.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function quote( string $value ): string {
		return "'" . $this->escape( $value ) . "'";
	}

	/**
	 * Backtick-quoted identifier.
	 *
	 * @param string $name Table or column name.
	 * @return string
	 */
	public static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Name of the connected database.
	 *
	 * @return string
	 */
	public function database(): string {
		return $this->name;
	}

	/**
	 * Server version string, for example "10.11.8-MariaDB".
	 *
	 * @return string
	 */
	public function server_version(): string {
		return (string) $this->mysqli->server_info;
	}

	/**
	 * Underlying mysqli object.
	 *
	 * @return \mysqli
	 */
	public function mysqli(): \mysqli {
		return $this->mysqli;
	}

	/**
	 * Closes the connection.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->mysqli->close();
	}

	/**
	 * Short, single-line excerpt of a statement for error messages.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	private static function excerpt( string $sql ): string {
		$sql = (string) preg_replace( '/\s+/', ' ', $sql );
		return strlen( $sql ) > 160 ? substr( $sql, 0, 160 ) . '…' : $sql;
	}
}
