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

use Founders\Migration\Archive\GzipFileSink;
use Founders\Migration\Database\SqlGuard;
use Founders\Migration\Database\SqlReader;
use Founders\Migration\Database\UnsafeSqlException;
use Founders\Migration\Tests\TestCase;

/**
 * Statement splitting and the restore allowlist.
 */
final class SqlToolsTest extends TestCase {

	const DUMP = <<<'SQL'
-- Dump header comment; with a semicolon
SET NAMES utf8mb4;
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
DROP TABLE IF EXISTS `wp_posts`;
CREATE TABLE `wp_posts` (
  `ID` bigint NOT NULL, # trailing comment
  `post_title` text, -- another one
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES (1,'It\'s; a \"test\"'),(2,'O''Reilly -- not a comment'),(3,'# nor this /* nor */'),(4,NULL),(5,0x00ff),(6,'back\\slash');
DELIMITER ;;
CREATE TRIGGER `wp_trim` BEFORE INSERT ON `wp_posts` FOR EACH ROW BEGIN SET NEW.post_title = TRIM(NEW.post_title); END;;
DELIMITER ;
INSERT INTO `wp_posts` VALUES (7,'last');
SQL;

	/**
	 * Every statement of a file.
	 *
	 * @param string $path File.
	 * @return array<int,array{sql:string,offset:int,delimiter:string}>
	 */
	private function statements( string $path ): array {
		$reader = new SqlReader( $path );
		$out    = array();
		while ( null !== ( $statement = $reader->next() ) ) {
			$out[] = $statement;
		}
		$reader->close();
		return $out;
	}

	public function test_splits_statements_respecting_quotes_comments_and_delimiters(): void {
		$statements = array_column( $this->statements( $this->make_file( 'dump.sql', self::DUMP ) ), 'sql' );

		$this->assertCount( 6, $statements, 'the versioned /*!...*/ comment leaves an empty statement, which is dropped' );
		$this->assertSame( 'SET NAMES utf8mb4', $statements[0] );
		$this->assertSame( 'DROP TABLE IF EXISTS `wp_posts`', $statements[1] );
		$this->assertStringContainsString( 'PRIMARY KEY', $statements[2] );
		$this->assertStringNotContainsString( 'trailing comment', $statements[2] );
		$this->assertStringContainsString( "(2,'O''Reilly -- not a comment')", $statements[3] );
		$this->assertStringContainsString( "(3,'# nor this /* nor */')", $statements[3] );
		$this->assertStringContainsString( 'TRIM(NEW.post_title); END', $statements[4] );
		$this->assertSame( "INSERT INTO `wp_posts` VALUES (7,'last')", $statements[5] );
	}

	public function test_offsets_resume_in_the_middle_including_delimiter_state(): void {
		$path = $this->tmp . '/dump.sql.gz';
		$sink = new GzipFileSink( $path );
		foreach ( str_split( self::DUMP, 97 ) as $piece ) {
			$sink->write( $piece );
			$sink->commit(); // Multi-member gzip, like a real dump.
		}
		$sink->close();

		$all = $this->statements( $path );
		foreach ( $all as $i => $statement ) {
			$reader = new SqlReader( $path, $statement['offset'], $statement['delimiter'] );
			$rest   = array();
			while ( null !== ( $next = $reader->next() ) ) {
				$rest[] = $next['sql'];
			}
			$reader->close();
			$this->assertSame( array_column( array_slice( $all, $i + 1 ), 'sql' ), $rest, "resume after statement $i" );
		}
	}

	public function test_statements_larger_than_the_read_buffer(): void {
		$big  = str_repeat( "x';y", 700000 );
		$path = $this->make_file( 'big.sql', "INSERT INTO t VALUES ('" . addslashes( $big ) . "');\nSET NAMES utf8mb4;\n" );

		$statements = $this->statements( $path );
		$this->assertCount( 2, $statements );
		$this->assertSame( strlen( "INSERT INTO t VALUES ('" . addslashes( $big ) . "')" ), strlen( $statements[0]['sql'] ) );
	}

	public function test_unterminated_input_is_an_error(): void {
		$this->expectException( \RuntimeException::class );
		$this->statements( $this->make_file( 'broken.sql', "INSERT INTO t VALUES ('never closed);\n" ) );
	}

	public function test_guard_moves_tables_to_the_temporary_prefix(): void {
		$guard = new SqlGuard( 'wp_', 'fmwtmp_' );

		$this->assertSame( 'DROP TABLE IF EXISTS `fmwtmp_posts`', $guard->table_statement( 'DROP TABLE IF EXISTS `wp_posts`' )['sql'] );
		$create = $guard->table_statement( 'CREATE TABLE `wp_wc_log` (`id` int, `wp_ref` int, CONSTRAINT `fk_log` FOREIGN KEY (`id`) REFERENCES `wp_wc_orders` (`id`)) ENGINE=InnoDB' );
		$this->assertSame( 'CREATE TABLE `fmwtmp_wc_log` (`id` int, `wp_ref` int, FOREIGN KEY (`id`) REFERENCES `fmwtmp_wc_orders` (`id`)) ENGINE=InnoDB', $create['sql'] );
		$insert = $guard->table_statement( "INSERT INTO `wp_options` (`option_name`) VALUES ('wp_user_roles'),(_binary 'x'),(0xABCD),(-1.5e-3),(NULL)" );
		$this->assertSame( "INSERT INTO `fmwtmp_options` (`option_name`) VALUES ('wp_user_roles'),(_binary 'x'),(0xABCD),(-1.5e-3),(NULL)", $insert['sql'] );
		$this->assertSame( 'wp_options', $insert['table'] );
		$this->assertSame( 'skip', $guard->table_statement( 'INSERT INTO other_table VALUES (1)' )['kind'] );
		$this->assertSame( 'set', $guard->table_statement( "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'" )['kind'] );
	}

	public function test_guard_ignores_transaction_control_from_dumps(): void {
		$guard = new SqlGuard( 'SERVMASK_PREFIX_', 'fmwtmp_' );
		foreach ( array( 'START TRANSACTION', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SET autocommit=0', 'SET SESSION autocommit = 1', 'LOCK TABLES `SERVMASK_PREFIX_posts` WRITE', 'UNLOCK TABLES' ) as $sql ) {
			$this->assertSame( 'skip', $guard->table_statement( $sql )['kind'], $sql );
		}
		$this->assertSame( 'CREATE TABLE `fmwtmp_posts` (id int)', $guard->table_statement( 'CREATE TABLE `SERVMASK_PREFIX_posts` (id int)' )['sql'] );
	}

	/**
	 * @dataProvider hostile_statements
	 *
	 * @param string $sql Statement an archive should never be able to run.
	 */
	public function test_guard_refuses_hostile_statements( string $sql ): void {
		$this->expectException( UnsafeSqlException::class );
		( new SqlGuard( 'wp_', 'fmwtmp_' ) )->table_statement( $sql );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function hostile_statements(): array {
		return array(
			'grant'               => array( "GRANT ALL ON *.* TO 'x'@'%'" ),
			'create user'         => array( "CREATE USER 'x'@'%' IDENTIFIED BY 'y'" ),
			'drop database'       => array( 'DROP DATABASE wordpress' ),
			'set global'          => array( 'SET GLOBAL general_log = 1' ),
			'set password'        => array( "SET PASSWORD = 'x'" ),
			'load data'           => array( "LOAD DATA INFILE '/etc/passwd' INTO TABLE wp_x" ),
			'select into outfile' => array( "SELECT '<?php' INTO OUTFILE '/var/www/shell.php'" ),
			'insert select'       => array( 'INSERT INTO wp_users SELECT * FROM mysql.user' ),
			'insert load_file'    => array( "INSERT INTO wp_options VALUES (1, 'x', LOAD_FILE('/etc/passwd'))" ),
			'insert subquery'     => array( 'INSERT INTO wp_options VALUES ((SELECT 1))' ),
			'insert expression'   => array( 'INSERT INTO wp_options VALUES (1, USER())' ),
			'create as select'    => array( 'CREATE TABLE wp_x (id int) AS SELECT * FROM mysql.user' ),
			'data directory'      => array( "CREATE TABLE wp_x (id int) DATA DIRECTORY = '/tmp'" ),
			'federated'           => array( "CREATE TABLE wp_x (id int) ENGINE=FEDERATED CONNECTION='mysql://a@b/c/d'" ),
			'connect engine'      => array( "CREATE TABLE wp_x (id int) ENGINE=CONNECT TABLE_TYPE=DOS FILE_NAME='/etc/passwd'" ),
			'drop other db table' => array( 'DROP TABLE mysql.user' ),
			'update'              => array( "UPDATE wp_users SET user_pass = 'x'" ),
			'unterminated'        => array( "INSERT INTO wp_x VALUES ('x)" ),
			'begin block'         => array( 'BEGIN NOT ATOMIC SELECT 1; END' ),
		);
	}

	public function test_view_and_trigger_statements_are_rewritten_to_the_new_prefix(): void {
		$guard = new SqlGuard( 'wp_', 'shop_' );

		$this->assertSame(
			"CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `shop_titles` AS select `shop_posts`.`ID` AS `ID` from `shop_posts` where `post_type` = 'wp_keep'",
			$guard->object_statement( "CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `wp_titles` AS select `wp_posts`.`ID` AS `ID` from `wp_posts` where `post_type` = 'wp_keep'" )
		);
		$this->assertSame(
			'CREATE TRIGGER shop_trim BEFORE INSERT ON shop_posts FOR EACH ROW SET NEW.post_title = TRIM(NEW.post_title)',
			$guard->object_statement( 'CREATE TRIGGER wp_trim BEFORE INSERT ON wp_posts FOR EACH ROW SET NEW.post_title = TRIM(NEW.post_title)' )
		);

		$this->expectException( UnsafeSqlException::class );
		$guard->object_statement( 'CREATE VIEW `wp_leak` AS select * from mysql.user' );
	}
}
