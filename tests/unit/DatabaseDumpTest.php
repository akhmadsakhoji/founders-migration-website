<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests\Unit;

use Founders\Migration\Database\Connection;
use Founders\Migration\Database\DatabaseException;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\StepRegistry;
use Founders\Migration\Model\Export\DatabaseStep;
use Founders\Migration\Tests\TestCase;

/**
 * Dumps a real MySQL / MariaDB database and restores it with the stock `mysql` client.
 *
 * Needs a server: set FMWP_TEST_DB_HOST, FMWP_TEST_DB_USER, FMWP_TEST_DB_PASSWORD
 * (defaults: localhost, root, empty). The user must be allowed to create the
 * databases fmwp_test and fmwp_test_restore. Skipped when no server is reachable.
 */
final class DatabaseDumpTest extends TestCase {

	const SOURCE  = 'fmwp_test';
	const RESTORE = 'fmwp_test_restore';
	const PREFIX  = 'wpt_';

	/**
	 * Job store.
	 *
	 * @var JobStore
	 */
	private $store;

	/**
	 * Registry with a "db" job type.
	 *
	 * @var StepRegistry
	 */
	private $registry;

	/**
	 * Server connection settings.
	 *
	 * @var array{host:string,user:string,pass:string}
	 */
	private $server;

	protected function setUp(): void {
		parent::setUp();

		$this->server = array(
			'host' => (string) ( getenv( 'FMWP_TEST_DB_HOST' ) ?: 'localhost' ),
			'user' => (string) ( getenv( 'FMWP_TEST_DB_USER' ) ?: 'root' ),
			'pass' => (string) ( getenv( 'FMWP_TEST_DB_PASSWORD' ) ?: '' ),
		);

		try {
			$admin = $this->connect( '' );
		} catch ( DatabaseException $e ) {
			$this->markTestSkipped( 'No MySQL/MariaDB server: ' . $e->getMessage() );
		}
		foreach ( array( self::SOURCE, self::RESTORE ) as $name ) {
			$admin->query( 'DROP DATABASE IF EXISTS ' . Connection::identifier( $name ) );
			$admin->query( 'CREATE DATABASE ' . Connection::identifier( $name ) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		}
		$admin->close();

		$this->seed( $this->connect( self::SOURCE ) );

		Connection::set_factory(
			function () {
				return $this->connect( self::SOURCE );
			}
		);
		$this->store    = new JobStore( $this->tmp . '/jobs' );
		$this->registry = new StepRegistry();
		$this->registry->register( 'db', array( DatabaseStep::class ) );
	}

	protected function tearDown(): void {
		Connection::set_factory( null );
		parent::tearDown();
	}

	/**
	 * Connects to a database on the test server.
	 *
	 * @param string $database Database name, '' for none.
	 * @return Connection
	 */
	private function connect( string $database ): Connection {
		list( $host, $port, $socket ) = Connection::parse_host( $this->server['host'] );
		return new Connection( $host, $this->server['user'], $this->server['pass'], $database, $port, $socket );
	}

	/**
	 * Creates the fixture schema and data.
	 *
	 * @param Connection $db Connection to the source database.
	 * @return void
	 */
	private function seed( Connection $db ): void {
		$p = self::PREFIX;
		$db->query( "CREATE TABLE {$p}posts (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title text NOT NULL, post_type varchar(20) NOT NULL DEFAULT 'post', post_content longtext) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}postmeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}comments (comment_ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, comment_approved varchar(20) NOT NULL, comment_content text) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}commentmeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, comment_id bigint unsigned NOT NULL, meta_value longtext) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}2_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title text NOT NULL, post_type varchar(20) NOT NULL) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}binary (id int NOT NULL PRIMARY KEY, small varbinary(64), big longblob, flags bit(8), uid binary(16)) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}composite (a int NOT NULL, b varchar(10) NOT NULL, c text, PRIMARY KEY (a, b)) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}nokey (x int, y text) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}uniq (u varchar(20) NOT NULL, v int, UNIQUE KEY u (u)) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}typed (id int NOT NULL PRIMARY KEY, price decimal(10,2), qty int, total decimal(12,2) AS (price * qty) STORED, huge bigint unsigned, ratio double, created datetime, doc json) ENGINE=InnoDB" );
		$db->query( "CREATE TABLE {$p}empty (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB" );
		$db->query( 'CREATE TABLE other_plugin_table (id int PRIMARY KEY, secret text) ENGINE=InnoDB' );
		$db->query( "INSERT INTO other_plugin_table VALUES (1, 'must not be exported')" );

		$titles = array( 'Hello "world"', "It's O'Reilly", 'Back\\slash', "Line\nbreak\r\n", 'Emoji 🚀 ñ 日本', " leading space", "tab\tand\0nul" );
		for ( $i = 1; $i <= 250; $i++ ) {
			$type = 0 === $i % 5 ? 'revision' : 'post';
			$db->query( sprintf( "INSERT INTO {$p}posts (post_title, post_type, post_content) VALUES (%s, '%s', %s)", $db->quote( $titles[ $i % count( $titles ) ] . " #$i" ), $type, $db->quote( str_repeat( "Paragraph $i. ", $i ) ) ) );
			$db->query( sprintf( "INSERT INTO {$p}postmeta (post_id, meta_key, meta_value) VALUES (%d, '_data', %s)", $i, $db->quote( serialize( array( 'url' => "https://example.com/p/$i", 'n' => $i ) ) ) ) );
		}
		for ( $i = 1; $i <= 40; $i++ ) {
			$db->query( sprintf( "INSERT INTO {$p}comments (comment_approved, comment_content) VALUES ('%s', 'Comment %d')", 0 === $i % 4 ? 'spam' : '1', $i ) );
			$db->query( sprintf( "INSERT INTO {$p}commentmeta (comment_id, meta_value) VALUES (%d, 'meta %d')", $i, $i ) );
		}
		foreach ( array( 'siteurl' => 'https://example.com', '_transient_feed' => 'x', '_site_transient_update' => 'y', 'blogname' => 'Example' ) as $name => $value ) {
			$db->query( "INSERT INTO {$p}options (option_name, option_value) VALUES ('{$name}', '{$value}')" );
		}
		$db->query( "INSERT INTO {$p}2_posts (post_title, post_type) VALUES ('Subsite post', 'post'), ('Subsite revision', 'revision')" );

		for ( $i = 1; $i <= 30; $i++ ) {
			$small = 1 === $i ? 'NULL' : ( 2 === $i ? "''" : '0x' . bin2hex( random_bytes( 1 + $i ) ) );
			$db->query( sprintf( "INSERT INTO {$p}binary VALUES (%d, %s, 0x%s, b'%08b', 0x%s)", $i, $small, bin2hex( "\0\xff" . random_bytes( 3000 ) ), $i, bin2hex( random_bytes( 16 ) ) ) );
		}
		for ( $a = 1; $a <= 12; $a++ ) {
			foreach ( array( 'x', 'y', 'z', 'zz' ) as $b ) {
				$db->query( "INSERT INTO {$p}composite VALUES ({$a}, '{$b}', 'c-{$a}-{$b}')" );
			}
		}
		for ( $i = 1; $i <= 120; $i++ ) {
			$db->query( "INSERT INTO {$p}nokey VALUES ({$i}, 'row {$i}')" );
		}
		for ( $i = 1; $i <= 25; $i++ ) {
			$db->query( "INSERT INTO {$p}uniq VALUES ('key-{$i}', {$i})" );
		}
		$db->query( "INSERT INTO {$p}typed (id, price, qty, huge, ratio, created, doc) VALUES (1, 19.99, 3, 18446744073709551615, 0.1, '2026-09-24 10:00:00', '{\"a\": [1, 2, {\"b\": \"c\"}]}'), (2, NULL, NULL, 0, -1.5e-300, NULL, NULL)" );

		$db->query( "CREATE DEFINER=CURRENT_USER VIEW {$p}post_titles AS SELECT ID, post_title FROM {$p}posts WHERE post_type = 'post'" );
		$db->query( "CREATE DEFINER=CURRENT_USER TRIGGER {$p}posts_trim BEFORE INSERT ON {$p}posts FOR EACH ROW BEGIN SET NEW.post_title = TRIM(NEW.post_title); END" );
		$db->close();
	}

	/**
	 * Runs a dump job to the end, one fresh "process" per call.
	 *
	 * @param array<string,mixed> $options  Job options.
	 * @param Deadline|null       $deadline Budget per call.
	 * @param callable|null       $between  Called between calls.
	 * @return Job
	 */
	private function dump( array $options, ?Deadline $deadline = null, ?callable $between = null ): Job {
		$job = $this->store->create( 'db', $options + array( 'table_prefix' => self::PREFIX ) );
		for ( $i = 0; $i < 5000; $i++ ) {
			$job = ( new Runner( $this->store, $this->registry, null, 0.001 ) )->run( $this->store->load( $job->id ), $deadline ?? Deadline::unlimited() );
			if ( Job::STATUS_RUNNING !== $job->status ) {
				$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
				return $job;
			}
			if ( null !== $between ) {
				$between( $job );
			}
		}
		$this->fail( 'Dump did not finish.' );
	}

	/**
	 * Restores every SQL part of a job into the restore database with the mysql client.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function restore_with_mysql_client( Job $job ): void {
		$client = $this->tool( 'mysql' ) ?? $this->tool( 'mariadb' );
		if ( null === $client ) {
			$this->markTestSkipped( 'The mysql command-line client is not installed.' );
		}
		list( $host, $port, $socket ) = Connection::parse_host( $this->server['host'] );
		$args = '--user=' . escapeshellarg( $this->server['user'] ) . ' --password=' . escapeshellarg( $this->server['pass'] ) . ( $socket ? ' --socket=' . escapeshellarg( $socket ) : ' --host=' . escapeshellarg( $host ) ) . ( $port ? ' --port=' . $port : '' );

		$paths = array_column( $job->data['parts'], 'path' );
		sort( $paths );
		foreach ( $paths as $path ) {
			$file                  = $this->store->dir( $job->id ) . '/' . $path;
			list( $code, $output ) = $this->run_command( 'gunzip -c ' . escapeshellarg( $file ) . " | {$client} {$args} " . self::RESTORE );
			$this->assertSame( 0, $code, $path . ': ' . $output );
		}
	}

	/**
	 * CHECKSUM TABLE and row count of every fixture table in a database.
	 *
	 * @param string $database Database.
	 * @return array<string,string>
	 */
	private function fingerprint( string $database ): array {
		$db     = $this->connect( $database );
		$tables = $db->column( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME" );
		$out    = array();
		foreach ( $tables as $table ) {
			$quoted          = Connection::identifier( (string) $table );
			$sum             = $db->rows( "CHECKSUM TABLE {$quoted}" )[0]['Checksum'];
			$count           = $db->column( "SELECT COUNT(*) FROM {$quoted}" )[0];
			$out[ $table ] = $count . ' rows, checksum ' . $sum;
		}
		$db->close();
		return $out;
	}

	public function test_dump_restores_identically_with_the_stock_mysql_client(): void {
		$job = $this->dump( array() );
		$this->restore_with_mysql_client( $job );

		$source = $this->fingerprint( self::SOURCE );
		unset( $source['other_plugin_table'] );
		$this->assertSame( $source, $this->fingerprint( self::RESTORE ) );

		$db = $this->connect( self::RESTORE );
		$this->assertSame( array( 'wpt_post_titles' ), $db->column( "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()" ) );
		$this->assertSame( array( 'wpt_posts_trim' ), $db->column( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()' ) );
		$this->assertSame( '18446744073709551615', $db->column( 'SELECT huge FROM wpt_typed WHERE id = 1' )[0], 'unsigned BIGINT kept exactly' );
		$this->assertSame( '59.97', $db->column( 'SELECT total FROM wpt_typed WHERE id = 1' )[0], 'generated column recomputed' );
		$db->close();

		$this->assertSame( 12, $job->data['database']['tables'] );
	}

	public function test_parts_are_named_hashed_and_free_of_definers(): void {
		$job = $this->dump(
			array(
				'sql_chunk_bytes' => 20000,
				'batch_rows'      => 20,
			)
		);
		$dir = $this->store->dir( $job->id );

		$tables = array();
		foreach ( $job->data['parts'] as $part ) {
			$this->assertMatchesRegularExpression( '#^database/\d{4}-[A-Za-z0-9_$-]+\.\d{4}\.sql\.gz$#', $part['path'] );
			$this->assertSame( hash_file( 'sha256', $dir . '/' . $part['path'] ), $part['sha256'] );
			$sql = $this->gunzip_all( (string) file_get_contents( $dir . '/' . $part['path'] ) );
			$this->assertSame( $part['bytes_raw'], strlen( $sql ), $part['path'] );
			$this->assertStringNotContainsString( 'DEFINER=', $sql );
			$this->assertStringNotContainsString( 'must not be exported', $sql );
			$this->assertSame( 0, strpos( $sql, DatabaseStep::HEADER ) );
			$tables[ $part['table'] ][] = $part['chunk'];
		}

		$this->assertGreaterThan( 2, count( $tables['wpt_posts'] ), 'large table split into chunks' );
		$this->assertSame( range( 1, count( $tables['wpt_posts'] ) ), $tables['wpt_posts'] );
		$this->assertArrayHasKey( 'views', $tables );
		$this->assertArrayHasKey( 'triggers', $tables );
		$this->assertArrayNotHasKey( 'other_plugin_table', $tables );
	}

	public function test_resumes_mid_table_across_processes_and_torn_writes(): void {
		$tear = function ( Job $job ) {
			$cursor = $job->cursor;
			if ( ! isset( $cursor['plan'][ $cursor['t'] ] ) || 0 === $cursor['at'] ) {
				return;
			}
			$file = $this->store->dir( $job->id ) . '/database/' . DatabaseStep::file_name( $cursor['t'] + 1, $cursor['plan'][ $cursor['t'] ]['name'], $cursor['chunk'] );
			file_put_contents( $file, random_bytes( 64 ), FILE_APPEND );
		};
		$job = $this->dump(
			array(
				'batch_rows'      => 7,
				'sql_chunk_bytes' => 30000,
			),
			new Deadline( 0.0 ),
			$tear
		);

		$this->restore_with_mysql_client( $job );
		$source = $this->fingerprint( self::SOURCE );
		unset( $source['other_plugin_table'] );
		$this->assertSame( $source, $this->fingerprint( self::RESTORE ) );
	}

	public function test_ai1wm_row_exclusions(): void {
		$job = $this->dump(
			array(
				'exclude'        => array( 'spam-comments', 'post-revisions', 'transients' ),
				'exclude_tables' => array( 'wpt_nokey' ),
			)
		);
		$this->restore_with_mysql_client( $job );

		$db = $this->connect( self::RESTORE );
		$this->assertSame( '200', $db->column( 'SELECT COUNT(*) FROM wpt_posts' )[0] );
		$this->assertSame( '0', $db->column( "SELECT COUNT(*) FROM wpt_posts WHERE post_type = 'revision'" )[0] );
		$this->assertSame( '200', $db->column( 'SELECT COUNT(*) FROM wpt_postmeta' )[0] );
		$this->assertSame( '30', $db->column( 'SELECT COUNT(*) FROM wpt_comments' )[0] );
		$this->assertSame( '30', $db->column( 'SELECT COUNT(*) FROM wpt_commentmeta' )[0] );
		$this->assertSame( array( 'blogname', 'siteurl' ), $db->column( 'SELECT option_name FROM wpt_options ORDER BY option_name' ) );
		$this->assertSame( array( 'Subsite post' ), $db->column( 'SELECT post_title FROM wpt_2_posts' ) );
		$this->assertSame( array(), $db->column( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wpt_nokey'" ) );
		$db->close();
	}
}
