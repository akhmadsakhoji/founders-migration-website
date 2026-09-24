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
use Founders\Migration\Model\Export\DatabaseStep;
use Founders\Migration\Tests\TestCase;

/**
 * Database helpers that need no server.
 */
final class DatabaseHelpersTest extends TestCase {

	public function test_db_host_parsing_matches_wordpress(): void {
		$this->assertSame( array( 'localhost', null, null ), Connection::parse_host( 'localhost' ) );
		$this->assertSame( array( 'db.example.com', 3307, null ), Connection::parse_host( 'db.example.com:3307' ) );
		$this->assertSame( array( 'localhost', null, '/var/run/mysqld/mysqld.sock' ), Connection::parse_host( 'localhost:/var/run/mysqld/mysqld.sock' ) );
		$this->assertSame( array( 'localhost', null, '/tmp/mysql.sock' ), Connection::parse_host( '/tmp/mysql.sock' ) );
		$this->assertSame( array( '::1', 3306, null ), Connection::parse_host( '[::1]:3306' ) );
	}

	public function test_definer_clauses_are_removed(): void {
		$this->assertSame(
			'CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v` AS select 1',
			DatabaseStep::strip_definer( 'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v` AS select 1' )
		);
		$this->assertSame( 'CREATE TRIGGER t BEFORE INSERT', DatabaseStep::strip_definer( "CREATE DEFINER='admin'@'%' TRIGGER t BEFORE INSERT" ) );
	}

	public function test_row_filters_cover_subsite_tables(): void {
		$flags = array_fill_keys( array( 'spam-comments', 'post-revisions', 'transients' ), true );

		$this->assertSame( "`post_type` <> 'revision'", DatabaseStep::row_filter( 'wp_posts', 'wp_', $flags ) );
		$this->assertSame( "`post_id` NOT IN (SELECT `ID` FROM `wp_3_posts` WHERE `post_type` = 'revision')", DatabaseStep::row_filter( 'wp_3_postmeta', 'wp_', $flags ) );
		$this->assertSame( '', DatabaseStep::row_filter( 'wp_2fa_posts', 'wp_', $flags ), 'plugin tables that merely start with digits are left alone' );
		$this->assertSame( '', DatabaseStep::row_filter( 'wp_posts', 'wp_', array() ) );
	}

	public function test_chunk_file_names_are_safe(): void {
		$this->assertSame( '0002-wp_posts.0003.sql.gz', DatabaseStep::file_name( 2, 'wp_posts', 3 ) );
		$this->assertSame( '0010-wp_odd_name_.0001.sql.gz', DatabaseStep::file_name( 10, 'wp_odd name!', 1 ) );
	}
}
