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
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Model\Import\FinalizeStep;
use Founders\Migration\Model\Import\RestoreDatabase;
use Founders\Migration\Model\Import\SwapStep;
use Founders\Migration\Model\Reset\ResetFilesStep;
use Founders\Migration\Model\Reset\ResetOptions;
use Founders\Migration\Model\Reset\TreeEraser;
use Founders\Migration\Tests\TestCase;

/**
 * Reset: emptying folders, keeping other installs' tables, and the database switch.
 *
 * The database tests need a MySQL/MariaDB server (see DatabaseDumpTest); skipped otherwise.
 * Building the fresh database needs WordPress and is covered by the end-to-end test.
 */
final class ResetTest extends TestCase {

	const DB     = 'fmwp_reset_test';
	const PLUGIN = 'founders-migration-website/founders-migration-website.php';

	protected function tearDown(): void {
		Connection::set_factory( null );
		parent::tearDown();
	}

	public function test_eraser_empties_a_folder_in_slices_and_keeps_what_it_must(): void {
		$files = array(
			'plugins/index.php'                                     => '<?php // Silence is golden.',
			'plugins/akismet/akismet.php'                           => '<?php',
			'plugins/akismet/views/a/b/c.php'                       => '<?php',
			'plugins/hello.php'                                     => '<?php',
			'plugins/founders-migration-website/loader.php'         => '<?php',
			'plugins/shop/backups-inside/keep-me.fmw'               => 'backup',
			'plugins/shop/shop.php'                                 => '<?php',
			'outside/precious.txt'                                  => 'must survive',
		);
		foreach ( $files as $path => $contents ) {
			$this->make_file( $path, $contents );
		}
		symlink( $this->tmp . '/outside', $this->tmp . '/plugins/linked-folder' );
		symlink( $this->tmp . '/outside/precious.txt', $this->tmp . '/plugins/linked-file.txt' );

		$root   = $this->tmp . '/plugins';
		$eraser = new TreeEraser( $root . '/', array( $root . '/index.php', $root . '/founders-migration-website', $root . '/shop/backups-inside' ) );

		$deleted = 0;
		$slices  = 0;
		do {
			++$slices;
			$budget = 2;
			$done   = $eraser->erase(
				static function () use ( &$budget ): bool {
					return --$budget > 0;
				},
				$deleted
			);
			$this->assertLessThan( 50, $slices );
		} while ( ! $done );

		$this->assertGreaterThan( 3, $slices, 'Deleting resumes over several slices.' );
		$this->assertSame( 10, $deleted ); // akismet (2 files, 4 folders), hello.php, shop.php, two links.
		$this->assertFileExists( $root . '/index.php' );
		$this->assertFileExists( $root . '/founders-migration-website/loader.php' );
		$this->assertFileExists( $root . '/shop/backups-inside/keep-me.fmw' );
		$this->assertFileDoesNotExist( $root . '/shop/shop.php' );
		$this->assertFileDoesNotExist( $root . '/akismet' );
		$this->assertFalse( is_link( $root . '/linked-folder' ) || is_link( $root . '/linked-file.txt' ) );
		$this->assertSame( 'must survive', file_get_contents( $this->tmp . '/outside/precious.txt' ), 'Links are removed, never followed.' );
		$this->assertTrue( $eraser->erase( array( $this, 'always' ), $deleted ), 'Running again is harmless.' );

		$this->assertTrue( ( new TreeEraser( $this->tmp . '/missing', array() ) )->erase( array( $this, 'always' ), $deleted ) );
	}

	public function test_folders_that_hold_wordpress_are_never_emptied(): void {
		foreach ( array( '/srv/www', '/srv/www/wp-content', '/srv', '' ) as $uploads ) {
			$job = $this->job( array( 'media' ), array( 'uploads_dir' => $uploads ) );
			try {
				ResetFilesStep::root( 'media', $job );
				$this->fail( 'Expected a refusal for "' . $uploads . '".' );
			} catch ( JobException $e ) {
				$this->assertStringContainsString( 'will not be emptied', $e->getMessage() );
			}
		}
		$job = $this->job( array( 'plugins', 'themes' ), array() );
		$this->assertSame( '/srv/www/wp-content/plugins', ResetFilesStep::root( 'plugins', $job ) );
		$this->assertContains( '/srv/www/wp-content/plugins/founders-migration-website', ResetFilesStep::keep( 'plugins', $job ) );
		$this->assertContains( '/srv/www/wp-content/fmw-backups', ResetFilesStep::keep( 'plugins', $job ) );
		$this->assertContains( '/srv/www/wp-content/themes/child', ResetFilesStep::keep( 'themes', $job ) );
		$this->assertContains( '/srv/www/wp-content/themes/parent', ResetFilesStep::keep( 'themes', $job ) );
		$this->assertContains( '/data/fmw-backups', ResetFilesStep::keep( 'media', $job ) );
	}

	public function test_site_tables_leave_other_installs_and_restore_leftovers_alone(): void {
		$db = $this->database();
		foreach ( array( 'wp_options', 'wp_posts', 'wp_shop_orders', 'wp_blog_options', 'wp_blog_posts', 'wpx_options', 'fmwold_posts' ) as $table ) {
			$db->query( "CREATE TABLE {$table} (id int PRIMARY KEY)" );
		}
		$this->assertSame( array( 'wp_options', 'wp_posts', 'wp_shop_orders' ), ( new RestoreDatabase() )->site_tables( 'wp_' ) );
		$this->assertSame( array( 'wp_blog_options', 'wp_blog_posts' ), ( new RestoreDatabase() )->site_tables( 'wp_blog_' ) );
		$this->assertSame( array( 'fmwold_posts' ), ( new RestoreDatabase() )->tables( 'fmwold_' ) );
		$this->assertSame( array(), ( new RestoreDatabase() )->site_tables( 'fmw' ) );
		$db->close();
	}

	public function test_a_fresh_database_replaces_every_table_of_the_site_in_one_switch(): void {
		$db = $this->database();
		$db->query( 'CREATE TABLE wp_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL)' );
		$db->query( "INSERT INTO wp_options VALUES ('blogname', 'Old site'), ('active_plugins', 'a:0:{}')" );
		$db->query( 'CREATE TABLE wp_posts (ID int PRIMARY KEY, post_title text)' );
		$db->query( "INSERT INTO wp_posts VALUES (1, 'Old post')" );
		$db->query( 'CREATE TABLE wp_shop_orders (id int PRIMARY KEY)' ); // A plugin table: not in a fresh install.
		$db->query( 'CREATE TABLE wp_blog_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL)' ); // Another site.
		$db->query( "INSERT INTO wp_blog_options VALUES ('blogname', 'Neighbour')" );
		$db->query( 'CREATE TABLE fmwtmp_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL)' );
		$db->query( "INSERT INTO fmwtmp_options VALUES ('blogname', 'Old site'), ('fresh_site', '1')" );
		$db->query( 'CREATE TABLE fmwtmp_posts (ID int PRIMARY KEY, post_title text)' );
		$db->query( 'CREATE VIEW wp_recent AS SELECT ID FROM wp_posts' );
		$db->query( 'CREATE VIEW wp_blog_recent AS SELECT option_name FROM wp_blog_options' );
		( new RestoreDatabase() )->create_progress();

		$job                 = $this->job( array( 'database' ), array() );
		$job->data['has_db'] = true;
		$job->data['manifest']['site']['table_prefix'] = 'wp_';
		$log     = array();
		$context = $this->context( $log );

		$this->assertTrue( ( new SwapStep() )->run( $job, $context ) );
		$this->assertSame( array( 'wp_blog_options', 'wp_options', 'wp_posts' ), ( new RestoreDatabase() )->tables( 'wp_' ) );
		$this->assertSame( array( 'fmwold_options', 'fmwold_posts', 'fmwold_shop_orders' ), ( new RestoreDatabase() )->tables( 'fmwold_' ) );
		$this->assertSame( array( '1' ), array_map( 'strval', $db->column( "SELECT option_value FROM wp_options WHERE option_name = 'fresh_site'" ) ) );
		$this->assertSame( array( serialize( array( self::PLUGIN ) ) ), $db->column( "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'" ) );
		$this->assertSame( array( '0' ), array_map( 'strval', $db->column( 'SELECT COUNT(*) FROM wp_posts' ) ) );
		$this->assertStringContainsString( 'fresh database (3 previous tables', implode( "\n", $log ) );
		$this->assertSame( array( 'wp_blog_recent' ), ( new RestoreDatabase() )->tables( 'wp_', 'VIEW' ), 'Views of the site go, those of the other site stay.' );

		$this->assertTrue( ( new SwapStep() )->run( $job, $context ), 'A second run (after a crash) switches nothing more.' );
		$this->assertTrue( ( new FinalizeStep() )->run( $job, $context ) );
		$this->assertSame( array(), ( new RestoreDatabase() )->tables( 'fmw' ) );
		$this->assertSame( array( 'Neighbour' ), $db->column( 'SELECT option_value FROM wp_blog_options' ) );
		$this->assertStringContainsString( 'Reset finished.', implode( "\n", $log ) );
		$db->close();
	}

	public function test_files_reset_without_database_reset_keeps_the_database_consistent(): void {
		$db = $this->database();
		$db->query( 'CREATE TABLE wp_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL)' );
		$db->query( "INSERT INTO wp_options VALUES ('active_plugins', '" . serialize( array( 'akismet/akismet.php', self::PLUGIN ) ) . "')" );
		$db->query( 'CREATE TABLE wp_posts (ID bigint PRIMARY KEY, post_type varchar(20))' );
		$db->query( "INSERT INTO wp_posts VALUES (1, 'post'), (2, 'attachment'), (3, 'attachment'), (4, 'page')" );
		$db->query( 'CREATE TABLE wp_postmeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, post_id bigint, meta_key varchar(255), meta_value longtext)' );
		$db->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, '_thumbnail_id', '2'), (1, 'color', 'blue'), (2, '_wp_attached_file', '2026/09/a.jpg'), (4, '_thumbnail_id', 'not-a-number')" );
		$db->query( 'CREATE TABLE wp_term_relationships (object_id bigint, term_taxonomy_id bigint, PRIMARY KEY (object_id, term_taxonomy_id))' );
		$db->query( 'INSERT INTO wp_term_relationships VALUES (1, 1), (3, 5)' );

		$content = $this->tmp . '/wp-content';
		foreach ( array( 'plugins/akismet/akismet.php', 'plugins/founders-migration-website/f.php', 'themes/child/style.css', 'themes/old/style.css', 'uploads/2026/09/a.jpg', 'fmw-backups/site.fmw' ) as $path ) {
			$this->make_file( 'wp-content/' . $path, 'x' );
		}
		$job = $this->job(
			array( 'plugins', 'themes', 'media' ),
			array(
				'abspath'     => $this->tmp . '/',
				'content_dir' => $content,
				'plugins_dir' => $content . '/plugins',
				'themes_dir'  => $content . '/themes',
				'uploads_dir' => $content . '/uploads',
			)
		);
		$log     = array();
		$context = $this->context( $log );
		$this->assertTrue( ( new ResetFilesStep() )->run( $job, $context ) );

		$this->assertSame( array( serialize( array( self::PLUGIN ) ) ), $db->column( "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'" ) );
		$this->assertSame( array( '1', '4' ), array_map( 'strval', $db->column( 'SELECT ID FROM wp_posts ORDER BY ID' ) ) );
		$this->assertSame( array( 'color', '_thumbnail_id' ), array_map( 'strval', $db->column( 'SELECT meta_key FROM wp_postmeta ORDER BY meta_id' ) ) );
		$this->assertSame( array( '1' ), array_map( 'strval', $db->column( 'SELECT object_id FROM wp_term_relationships' ) ) );
		$this->assertFileDoesNotExist( $content . '/plugins/akismet' );
		$this->assertFileExists( $content . '/plugins/founders-migration-website/f.php' );
		$this->assertFileExists( $content . '/themes/child/style.css' );
		$this->assertFileDoesNotExist( $content . '/themes/old' );
		$this->assertFileDoesNotExist( $content . '/uploads/2026' );
		$this->assertDirectoryExists( $content . '/uploads' );
		$this->assertFileExists( $content . '/fmw-backups/site.fmw' );
		$this->assertStringContainsString( 'Removed 2 media library entries.', implode( "\n", $log ) );
		$db->close();
	}

	public function test_one_site_of_a_network_is_reset_with_its_own_tables_and_roles(): void {
		$db = $this->database();
		$db->query( 'CREATE TABLE wp_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL)' ); // The main site's.
		$db->query( "INSERT INTO wp_options VALUES ('active_plugins', 'a:0:{}')" );
		$db->query( 'CREATE TABLE wp_usermeta (umeta_id bigint AUTO_INCREMENT PRIMARY KEY, user_id bigint, meta_key varchar(255), meta_value longtext)' );
		$db->query( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (5, 'wp_capabilities', 'main'), (6, 'wp_3_capabilities', 'editor'), (6, 'wp_3_user_level', '7'), (6, 'wp_4_capabilities', 'author'), (7, 'wp_3_capabilities', 'subscriber')" );
		foreach ( array( 3, 4 ) as $id ) {
			$db->query( "CREATE TABLE wp_{$id}_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL)" );
			$db->query( "INSERT INTO wp_{$id}_options VALUES ('blogname', 'Site {$id}')" );
			$db->query( "CREATE TABLE wp_{$id}_posts (ID int PRIMARY KEY, post_title text)" );
			$db->query( "INSERT INTO wp_{$id}_posts VALUES (1, 'Post of {$id}')" );
		}
		$db->query( 'CREATE TABLE wp_3_shop_orders (id int PRIMARY KEY)' ); // Site 3's plugin table.
		$db->query( 'CREATE TABLE fmwold_4_posts (id int PRIMARY KEY)' ); // Kept by an earlier reset of site 4 (--keep-old-tables).
		$db->query( 'CREATE TABLE wp_blogs (blog_id bigint PRIMARY KEY, domain varchar(200), path varchar(100))' );
		$db->query( "INSERT INTO wp_blogs VALUES (1, 'net.test', '/'), (3, 'net.test', '/three/'), (4, 'net.test', '/four/')" );
		$db->query( 'CREATE TABLE wp_site (id bigint PRIMARY KEY, domain varchar(200), path varchar(100))' );
		$db->query( "INSERT INTO wp_site VALUES (1, 'net.test', '/'), (2, 'other.test', '/')" ); // Two networks.
		$db->query( 'CREATE TABLE wp_sitemeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, site_id bigint, meta_key varchar(255), meta_value longtext)' );
		$db->query( "INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1, 'active_sitewide_plugins', 'a:0:{}'), (2, 'active_sitewide_plugins', 'a:0:{}')" );
		$db->query( 'CREATE TABLE wp_30_posts (id int PRIMARY KEY)' ); // Site 30, not 3.
		$db->query( 'CREATE VIEW wp_3_recent AS SELECT ID FROM wp_3_posts' );
		$db->query( 'CREATE VIEW wp_recent AS SELECT option_name FROM wp_options' );
		$db->query( 'CREATE TABLE fmwtmp_3_options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL)' );
		$db->query( "INSERT INTO fmwtmp_3_options VALUES ('blogname', 'Site 3'), ('fresh_site', '1')" );
		$db->query( 'CREATE TABLE fmwtmp_3_posts (ID int PRIMARY KEY, post_title text)' );
		( new RestoreDatabase() )->create_progress();

		$job                         = $this->job( array( 'database' ), array( 'network' => array( 'id' => 1, 'main_site' => 1 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$job->options['reset_site']  = 9; // Gone meanwhile.
		$job->options['keep_users']  = array( 5 );
		$job->data['has_db']         = true;
		$job->data['manifest']['site']['table_prefix'] = 'wp_';
		$log                                           = array();
		try {
			( new SwapStep() )->run( $job, $this->context( $log ) );
			$this->fail( 'A site that is gone was switched in.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'Site 9 is gone', $e->getMessage() );
		}
		$this->assertSame( array( 'fmwtmp_3_options', 'fmwtmp_3_posts' ), ( new RestoreDatabase() )->tables( 'fmwtmp_3' ) );
		$job->options['reset_site']          = 3;
		$job->options['keep_active_network'] = true; // Only this network's list, never another network's.
		$job->data['has_db']         = true;
		$job->data['manifest']['site']['table_prefix'] = 'wp_';
		$log     = array();
		$context = $this->context( $log );
		$this->assertTrue( ( new SwapStep() )->run( $job, $context ) );
		$this->assertTrue( ( new SwapStep() )->run( $job, $context ), 'A second run (after a crash) switches nothing more.' );

		$this->assertSame( array( '1' ), array_map( 'strval', $db->column( "SELECT option_value FROM wp_3_options WHERE option_name = 'fresh_site'" ) ) );
		$this->assertSame( array( '0' ), array_map( 'strval', $db->column( 'SELECT COUNT(*) FROM wp_3_posts' ) ) );
		$this->assertSame( array( 'fmwold_3_options', 'fmwold_3_posts', 'fmwold_3_shop_orders' ), ( new RestoreDatabase() )->tables( 'fmwold_3' ) );
		$this->assertSame( array( 'Post of 4' ), $db->column( 'SELECT post_title FROM wp_4_posts' ) );
		$this->assertSame( array( 'wp_30_posts' ), ( new RestoreDatabase() )->tables( 'wp_30' ) );
		$this->assertSame( array( 'a:0:{}' ), $db->column( "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'" ), 'The main site\'s plugins are not touched.' );
		$this->assertSame( array( 'wp_recent' ), ( new RestoreDatabase() )->tables( 'wp_', 'VIEW' ) );
		$this->assertSame(
			array( '5 wp_3_capabilities a:1:{s:13:"administrator";b:1;}', '5 wp_3_user_level 10', '5 wp_capabilities main', '6 wp_4_capabilities author' ),
			$db->column( "SELECT CONCAT(user_id, ' ', meta_key, ' ', meta_value) FROM wp_usermeta ORDER BY user_id, meta_key" )
		);
		$this->assertStringContainsString( 'Site 3: 1 user(s) are its administrators', implode( "\n", $log ) );
		$this->assertSame( array( '1 1', '2 0' ), $db->column( "SELECT CONCAT(site_id, ' ', meta_value LIKE '%founders%') FROM wp_sitemeta ORDER BY site_id" ) );

		// Only what this reset moved aside is removed; site 4's kept tables stay.
		$this->assertTrue( ( new FinalizeStep() )->run( $job, $context ) );
		$this->assertSame( array( 'fmwold_4_posts' ), ( new RestoreDatabase() )->tables( 'fmwold_' ) );
		$db->close();
	}

	public function test_a_whole_network_is_switched_to_a_fresh_one(): void {
		$db = $this->database();
		foreach ( array( 'wp_options', 'wp_posts', 'wp_users', 'wp_blogs', 'wp_sitemeta', 'wp_2_options', 'wp_2_posts', 'wp_2_shop_orders', 'wp_7_posts', 'wp_2fa_codes' ) as $table ) {
			$db->query( "CREATE TABLE {$table} (id int PRIMARY KEY)" );
		}
		$db->query( 'DROP TABLE wp_blogs' );
		$db->query( 'CREATE TABLE wp_blogs (blog_id bigint PRIMARY KEY)' );
		$db->query( 'INSERT INTO wp_blogs VALUES (1), (2), (7)' );
		$db->query( 'CREATE TABLE wp_9_posts (id int PRIMARY KEY)' ); // Left by a deleted site 9: the network's too.
		$db->query( 'CREATE TABLE wp_5_options (id int PRIMARY KEY)' ); // Another install with the prefix wp_5_ (no site 5).
		$db->query( 'CREATE TABLE wp_5_posts (id int PRIMARY KEY)' );
		$db->query( 'CREATE TABLE other_posts (id int PRIMARY KEY)' ); // Another install.
		$db->query( 'CREATE VIEW wp_2_recent AS SELECT id FROM wp_2_posts' );
		foreach ( array( 'options', 'posts', 'users', 'sitemeta' ) as $table ) {
			$db->query( "CREATE TABLE fmwtmp_{$table} (id int PRIMARY KEY)" );
		}
		$db->query( 'CREATE TABLE fmwtmp_blogs (blog_id bigint PRIMARY KEY)' );
		$db->query( 'INSERT INTO fmwtmp_blogs VALUES (1)' );
		( new RestoreDatabase() )->create_progress();

		$job                            = $this->job( array( 'database' ), array() );
		$job->options['reset_network']  = true;
		$job->options['keep_active_plugin'] = '';
		$job->data['has_db']            = true;
		$job->data['manifest']['site']['table_prefix'] = 'wp_';
		$log = array();
		$this->assertTrue( ( new SwapStep() )->run( $job, $this->context( $log ) ) );

		$this->assertSame( array( 'wp_5_options', 'wp_5_posts', 'wp_blogs', 'wp_options', 'wp_posts', 'wp_sitemeta', 'wp_users' ), ( new RestoreDatabase() )->tables( 'wp_' ) );
		$this->assertSame( array( '1' ), array_map( 'strval', $db->column( 'SELECT blog_id FROM wp_blogs' ) ) );
		$aside = ( new RestoreDatabase() )->tables( 'fmwold_' );
		sort( $aside );
		$this->assertSame( array( 'fmwold_2_options', 'fmwold_2_posts', 'fmwold_2_shop_orders', 'fmwold_2fa_codes', 'fmwold_7_posts', 'fmwold_9_posts', 'fmwold_blogs', 'fmwold_options', 'fmwold_posts', 'fmwold_sitemeta', 'fmwold_users' ), $aside );
		$this->assertSame( array(), ( new RestoreDatabase() )->tables( 'wp_', 'VIEW' ) );
		$this->assertSame( array( 'other_posts' ), ( new RestoreDatabase() )->tables( 'other_' ) );
		$this->assertTrue( ( new FinalizeStep() )->run( $job, $this->context( $log ) ) );
		$this->assertSame( array(), ( new RestoreDatabase() )->tables( 'fmwold_' ) );
		$db->close();
	}

	public function test_files_of_a_whole_network_without_its_database(): void {
		$db = $this->database();
		$db->query( 'CREATE TABLE wp_blogs (blog_id bigint PRIMARY KEY)' );
		$db->query( 'INSERT INTO wp_blogs VALUES (1), (3)' );
		$db->query( 'CREATE TABLE wp_sitemeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, site_id bigint, meta_key varchar(255), meta_value longtext)' );
		$db->query( "INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1, 'active_sitewide_plugins', '" . serialize( array( 'akismet/akismet.php' => 1, self::PLUGIN => 2 ) ) . "')" );
		foreach ( array( 'wp_', 'wp_3_' ) as $prefix ) {
			$db->query( "CREATE TABLE {$prefix}options (option_name varchar(191) PRIMARY KEY, option_value longtext NOT NULL)" );
			$db->query( "INSERT INTO {$prefix}options VALUES ('active_plugins', '" . serialize( array( 'shop/shop.php' ) ) . "')" );
			$db->query( "CREATE TABLE {$prefix}posts (ID bigint PRIMARY KEY, post_type varchar(20))" );
			$db->query( "INSERT INTO {$prefix}posts VALUES (1, 'attachment'), (2, 'post')" );
			$db->query( "CREATE TABLE {$prefix}postmeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, post_id bigint, meta_key varchar(255), meta_value longtext)" );
			$db->query( "CREATE TABLE {$prefix}term_relationships (object_id bigint, term_taxonomy_id bigint, PRIMARY KEY (object_id, term_taxonomy_id))" );
		}
		$content = $this->tmp . '/wp-content';
		foreach ( array( 'plugins/shop/shop.php', 'plugins/founders-migration-website/f.php', 'uploads/2026/a.jpg', 'uploads/sites/3/2026/b.jpg' ) as $path ) {
			$this->make_file( 'wp-content/' . $path, 'x' );
		}
		$job                           = $this->job(
			array( 'plugins', 'media' ),
			array(
				'abspath'     => $this->tmp . '/',
				'content_dir' => $content,
				'plugins_dir' => $content . '/plugins',
				'uploads_dir' => $content . '/uploads',
			)
		);
		$job->options['reset_network'] = true;
		$log                           = array();
		$this->assertTrue( ( new ResetFilesStep() )->run( $job, $this->context( $log ) ) );
		$this->assertSame( array( serialize( array( self::PLUGIN => 2 ) ) ), $db->column( "SELECT meta_value FROM wp_sitemeta WHERE meta_key = 'active_sitewide_plugins'" ) );
		$this->assertSame( array( 'a:0:{}', 'a:0:{}' ), array( $db->column( "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'" )[0], $db->column( "SELECT option_value FROM wp_3_options WHERE option_name = 'active_plugins'" )[0] ) );
		$this->assertSame( array( '2', '2' ), array( (string) $db->column( 'SELECT ID FROM wp_posts' )[0], (string) $db->column( 'SELECT ID FROM wp_3_posts' )[0] ) );
		$this->assertFileDoesNotExist( $content . '/plugins/shop' );
		$this->assertFileExists( $content . '/plugins/founders-migration-website/f.php' );
		$this->assertFileDoesNotExist( $content . '/uploads/sites' );
		$this->assertFileDoesNotExist( $content . '/uploads/2026' );
		$this->assertStringContainsString( 'on the network and its 2 sites', implode( "\n", $log ) );
		$db->close();
	}

	public function test_media_of_one_site_of_a_network_only(): void {
		$db = $this->database();
		foreach ( array( 'wp_', 'wp_3_' ) as $prefix ) {
			$db->query( "CREATE TABLE {$prefix}posts (ID bigint PRIMARY KEY, post_type varchar(20))" );
			$db->query( "INSERT INTO {$prefix}posts VALUES (1, 'attachment'), (2, 'post')" );
			$db->query( "CREATE TABLE {$prefix}postmeta (meta_id bigint AUTO_INCREMENT PRIMARY KEY, post_id bigint, meta_key varchar(255), meta_value longtext)" );
			$db->query( "CREATE TABLE {$prefix}term_relationships (object_id bigint, term_taxonomy_id bigint, PRIMARY KEY (object_id, term_taxonomy_id))" );
		}
		$content = $this->tmp . '/wp-content';
		foreach ( array( 'uploads/2026/main.jpg', 'uploads/sites/3/2026/three.jpg', 'uploads/sites/4/2026/four.jpg' ) as $path ) {
			$this->make_file( 'wp-content/' . $path, 'x' );
		}
		$target                     = array(
			'abspath'     => $this->tmp . '/',
			'content_dir' => $content,
			'uploads_dir' => $content . '/uploads/sites/3',
		);
		$job                        = $this->job( array( 'media' ), $target );
		$job->options['reset_site'] = 3;
		$log                        = array();
		$this->assertTrue( ( new ResetFilesStep() )->run( $job, $this->context( $log ) ) );
		$this->assertFileDoesNotExist( $content . '/uploads/sites/3/2026' );
		$this->assertFileExists( $content . '/uploads/sites/4/2026/four.jpg' );
		$this->assertFileExists( $content . '/uploads/2026/main.jpg' );
		$this->assertSame( array( '2' ), array_map( 'strval', $db->column( 'SELECT ID FROM wp_3_posts' ) ) );
		$this->assertSame( array( '1', '2' ), array_map( 'strval', $db->column( 'SELECT ID FROM wp_posts ORDER BY ID' ) ) );

		// Never the network's own uploads folder, whatever the target says.
		$job                        = $this->job( array( 'media' ), array( 'uploads_dir' => $content . '/uploads' ) + $target );
		$job->options['reset_site'] = 3;
		try {
			( new ResetFilesStep() )->run( $job, $this->context( $log ) );
			$this->fail( 'The network\'s uploads folder was emptied.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'Only the media folder of site 3', $e->getMessage() );
		}
		$this->assertFileExists( $content . '/uploads/2026/main.jpg' );
		$this->assertTrue( ResetOptions::own_uploads( '/srv/wp-content/blogs.dir/3/files', 3 ) );
		$this->assertFalse( ResetOptions::own_uploads( '/srv/wp-content/uploads/sites/33', 3 ) );
		$this->assertFalse( ResetOptions::own_uploads( '/sites/3', 3 ) );
		$db->close();
	}

	/**
	 * Slice callback that never stops.
	 *
	 * @return bool
	 */
	public function always(): bool {
		return true;
	}

	/**
	 * A reset job for a site in /srv/www (or the given folders).
	 *
	 * @param string[]             $parts  Parts.
	 * @param array<string,string> $target Target overrides.
	 * @return Job
	 */
	private function job( array $parts, array $target ): Job {
		return new Job(
			'01J0000000000000000000000R',
			'reset',
			array(
				'reset'              => $parts,
				'target'             => $target + array(
					'abspath'      => '/srv/www/',
					'content_dir'  => '/srv/www/wp-content',
					'plugins_dir'  => '/srv/www/wp-content/plugins',
					'themes_dir'   => '/srv/www/wp-content/themes',
					'uploads_dir'  => '/srv/www/wp-content/uploads',
					'table_prefix' => 'wp_',
				),
				'protect_paths'      => array( 'plugins/founders-migration-website', 'fmw-backups', 'fmw-storage' ),
				'keep_active_plugin' => self::PLUGIN,
				'keep_themes'        => array( 'parent', 'child' ),
				'keep_paths'         => array( '/data/fmw-backups/' ),
				'replace_all_tables' => true,
			)
		);
	}

	/**
	 * A context without a time limit that collects the log.
	 *
	 * @param string[] $log Log lines.
	 * @return Context
	 */
	private function context( array &$log ): Context {
		return new Context(
			$this->tmp,
			new Deadline( null ),
			60.0,
			static function (): bool {
				return false;
			},
			static function ( string $message ) use ( &$log ): void {
				$log[] = $message;
			}
		);
	}

	/**
	 * A fresh test database, which Connection::open() then returns.
	 *
	 * @return Connection
	 */
	private function database(): Connection {
		$host   = (string) ( getenv( 'FMWP_TEST_DB_HOST' ) ?: 'localhost' );
		$user   = (string) ( getenv( 'FMWP_TEST_DB_USER' ) ?: 'root' );
		$pass   = (string) ( getenv( 'FMWP_TEST_DB_PASSWORD' ) ?: '' );
		$server = Connection::parse_host( $host );
		try {
			$admin = new Connection( $server[0], $user, $pass, '', $server[1], $server[2] );
		} catch ( DatabaseException $e ) {
			$this->markTestSkipped( 'No MySQL/MariaDB server: ' . $e->getMessage() );
		}
		$admin->query( 'DROP DATABASE IF EXISTS ' . self::DB );
		$admin->query( 'CREATE DATABASE ' . self::DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$admin->close();

		$connect = static function () use ( $server, $user, $pass ): Connection {
			$db = new Connection( $server[0], $user, $pass, self::DB, $server[1], $server[2] );
			$db->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" ); // Comparisons must not fail on hosts in strict mode.
			return $db;
		};
		Connection::set_factory( $connect );
		return $connect();
	}
}
