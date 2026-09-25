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

use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Archive\GzipFileSink;
use Founders\Migration\Archive\PasswordException;
use Founders\Migration\Archive\PlainFileSink;
use Founders\Migration\Archive\TarWriter;
use Founders\Migration\Database\Connection;
use Founders\Migration\Database\DatabaseException;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\StepRegistry;
use Founders\Migration\Model\Export\DatabaseStep;
use Founders\Migration\Model\Export\FilesStep;
use Founders\Migration\Model\Export\PackageStep;
use Founders\Migration\Model\Export\ScanStep;
use Founders\Migration\Model\Import\CheckStep;
use Founders\Migration\Model\Import\FinalizeStep;
use Founders\Migration\Model\Import\PartsStep;
use Founders\Migration\Model\Import\ReplaceStep;
use Founders\Migration\Model\Import\SwapStep;
use Founders\Migration\Tests\TestCase;

/**
 * Backup one site, restore it onto another (new URL, path and table prefix) and check the result.
 *
 * Needs a MySQL/MariaDB server (see DatabaseDumpTest); skipped otherwise.
 */
final class RestoreTest extends TestCase {

	const SOURCE = 'fmwp_restore_src';
	const TARGET = 'fmwp_restore_dst';
	const PLUGIN = 'founders-migration-website/founders-migration-website.php';

	/**
	 * Server settings.
	 *
	 * @var array{host:string,user:string,pass:string}
	 */
	private $server;

	/**
	 * Registry with backup and restore types.
	 *
	 * @var StepRegistry
	 */
	private $registry;

	/**
	 * Database the Connection factory currently points at.
	 *
	 * @var string
	 */
	private $current = self::SOURCE;

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
		foreach ( array( self::SOURCE, self::TARGET ) as $name ) {
			$admin->query( "DROP DATABASE IF EXISTS {$name}" );
			$admin->query( "CREATE DATABASE {$name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
		}
		$admin->close();

		Connection::set_factory(
			function () {
				return $this->connect( $this->current );
			}
		);
		$this->registry = new StepRegistry();
		$this->registry->register( 'backup', array( ScanStep::class, DatabaseStep::class, FilesStep::class, PackageStep::class ) );
		$this->registry->register( 'restore', array( CheckStep::class, PartsStep::class, ReplaceStep::class, SwapStep::class, FinalizeStep::class ) );

		$this->seed_source();
		$this->seed_target();
	}

	protected function tearDown(): void {
		Connection::set_factory( null );
		parent::tearDown();
	}

	/**
	 * Connects to a database on the test server.
	 *
	 * @param string $database Database, '' for none.
	 * @return Connection
	 */
	private function connect( string $database ): Connection {
		list( $host, $port, $socket ) = Connection::parse_host( $this->server['host'] );
		return new Connection( $host, $this->server['user'], $this->server['pass'], $database, $port, $socket );
	}

	/**
	 * Source site: https://example.com in /home/old/public_html, prefix wp_.
	 *
	 * @return void
	 */
	private function seed_source(): void {
		$files = array(
			'themes/astra/style.css'                         => str_repeat( "body{}\n", 3000 ),
			'uploads/2026/09/photo.jpg'                      => random_bytes( 120000 ),
			'plugins/shop/shop.php'                          => '<?php // shop',
			'plugins/founders-migration-website/old-fmw.php' => '<?php // older FMW inside the backup',
		);
		foreach ( $files as $path => $contents ) {
			$this->make_file( 'source/wp-content/' . $path, $contents );
		}
		symlink( '/etc', $this->tmp . '/source/wp-content/uploads/etc-link' );

		$db  = $this->connect( self::SOURCE );
		$url = 'https://example.com';
		$db->query( 'CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL)' );
		$db->query( 'CREATE TABLE wp_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, guid varchar(255) NOT NULL, post_title text, post_content longtext)' );
		$db->query( 'CREATE TABLE wp_usermeta (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext)' );
		$db->query( 'CREATE TABLE wp_users (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_login varchar(60), user_email varchar(100))' );
		$options = array(
			'siteurl'        => $url,
			'home'           => $url,
			'admin_email'    => 'admin@example.com',
			'widget_text'    => serialize( array( 2 => array( 'text' => "<a href=\"{$url}/promo\">Promo ñ</a>" ), '_multiwidget' => 1 ) ), // phpcs:ignore
			'upload_path'    => '/home/old/public_html/wp-content/uploads',
			'theme_mods'     => json_encode( array( 'logo' => $url . '/logo.png' ) ),
			'wp_user_roles'  => serialize( array( 'administrator' => array( 'name' => 'Administrator' ) ) ), // phpcs:ignore
			'active_plugins' => serialize( array( 'shop/shop.php' ) ), // phpcs:ignore
		);
		foreach ( $options as $name => $value ) {
			$db->query( 'INSERT INTO wp_options (option_name, option_value) VALUES (' . $db->quote( $name ) . ', ' . $db->quote( $value ) . ')' );
		}
		for ( $i = 1; $i <= 1200; $i++ ) {
			$db->query( sprintf( "INSERT INTO wp_posts (guid, post_title, post_content) VALUES ('%s/?p=%d', 'Post %d', %s)", $url, $i, $i, $db->quote( "See {$url}/page-{$i} and //example.com/img-{$i}.jpg" ) ) );
		}
		$db->query( "INSERT INTO wp_users (user_login, user_email) VALUES ('admin', 'admin@example.com')" );
		$db->query( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_capabilities', 'a:1:{s:13:\"administrator\";b:1;}'), (1, 'wp_user_level', '10'), (1, 'nickname', 'admin')" );
		$db->query( "CREATE VIEW wp_titles AS SELECT ID, post_title FROM wp_posts WHERE ID < 10" );
		$db->query( "CREATE TRIGGER wp_posts_bi BEFORE INSERT ON wp_posts FOR EACH ROW SET NEW.post_title = TRIM(NEW.post_title)" );
		$db->query( 'CREATE TABLE unrelated_plugin (id int PRIMARY KEY)' );
		$db->close();
	}

	/**
	 * Target site: a different, older site with prefix shop_.
	 *
	 * @return void
	 */
	private function seed_target(): void {
		$this->make_file( 'target/wp-content/themes/astra/style.css', 'old theme' );
		$this->make_file( 'target/wp-content/plugins/founders-migration-website/founders-migration-website.php', '<?php // running FMW' );

		$db = $this->connect( self::TARGET );
		$db->query( 'CREATE TABLE shop_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL)' );
		$db->query( "INSERT INTO shop_options (option_name, option_value) VALUES ('siteurl', 'https://old-target.test')" );
		$db->query( 'CREATE TABLE shop_extra (id int PRIMARY KEY)' );
		$db->query( 'INSERT INTO shop_extra VALUES (42)' );
		$db->query( 'CREATE TABLE other_site_posts (id int PRIMARY KEY)' );
		$db->close();
	}

	/**
	 * Runs a job type to the end, one fresh "process" per slice.
	 *
	 * @param JobStore            $store    Store.
	 * @param string              $type     Job type.
	 * @param array<string,mixed> $options  Options.
	 * @param string              $database Database the steps connect to.
	 * @param callable|null       $between  Called between slices.
	 * @return Job
	 */
	private function run_job( JobStore $store, string $type, array $options, string $database, ?callable $between = null ): Job {
		$this->current = $database;
		$job           = $store->create( $type, $options );
		for ( $i = 0; $i < 20000; $i++ ) {
			$job = ( new Runner( $store, $this->registry, null, 0.001 ) )->run( $store->load( $job->id ), new Deadline( 0.0 ) );
			if ( Job::STATUS_RUNNING !== $job->status ) {
				return $job;
			}
			if ( null !== $between ) {
				$between( $job );
			}
		}
		$this->fail( 'Job did not finish.' );
	}

	/**
	 * Backs up the source site.
	 *
	 * @param string              $password Password for an encrypted backup, '' for none.
	 * @param array<string,mixed> $site     Manifest site fields to change.
	 * @return string Archive path.
	 */
	private function backup( string $password = '', array $site = array() ): string {
		$encryption = '' === $password ? array() : array(
			'encrypt'         => true,
			'kdf_iterations'  => 100000,
			'secret_password' => Secrets::seal( $password ),
			'archive_name'    => 'backup-20260924-180000-a1b2c3.fmw', // Encrypted backups name no site.
		);
		$job        = $this->run_job(
			new JobStore( $this->tmp . '/backup-jobs' ),
			'backup',
			$encryption + array(
				'content_dir'     => $this->tmp . '/source/wp-content',
				'table_prefix'    => 'wp_',
				'part_size'       => 100000,
				'batch_rows'      => 100,
				'sql_chunk_bytes' => 40000,
				'archive_dir'     => $this->tmp . '/archives',
				'archive_name'    => 'example.com-20260924-180000-a1b2c3.fmw',
				'site'            => $site + array(
					'home_url'     => 'https://example.com',
					'site_url'     => 'https://example.com',
					'abspath'      => '/home/old/public_html/',
					'table_prefix' => 'wp_',
					'multisite'    => false,
				),
			),
			self::SOURCE
		);
		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		return $job->data['archive']['path'];
	}

	/**
	 * Restore options for the target site.
	 *
	 * @param string $archive  Archive path.
	 * @param string $password Password of an encrypted backup.
	 * @return array<string,mixed>
	 */
	private function restore_options( string $archive, string $password = '' ): array {
		return ( '' === $password ? array() : array( 'secret_password' => Secrets::seal( $password ) ) ) + array(
			'archive'            => $archive,
			'target'             => array(
				'home_url'     => 'https://example.com/staging',
				'site_url'     => 'https://example.com/staging',
				'abspath'      => '/home/new/www/',
				'content_dir'  => $this->tmp . '/target/wp-content',
				'table_prefix' => 'shop_',
				'multisite'    => false,
			),
			'protect_paths'      => array( 'plugins/founders-migration-website', 'fmw-backups', 'fmw-storage' ),
			'email_replace'      => true,
			'keep_active_plugin' => self::PLUGIN,
			'skip_space_check'   => true,
		);
	}

	public function test_restores_onto_a_new_url_path_and_prefix_resuming_after_every_slice(): void {
		$archive = $this->backup();
		$store   = new JobStore( $this->tmp . '/restore-jobs' );
		$job     = $this->run_job( $store, 'restore', $this->restore_options( $archive ), self::TARGET );
		$this->assert_restored( $store, $job );
	}

	public function test_an_encrypted_backup_hides_the_site_and_restores_only_with_its_password(): void {
		$password = 'Kata sandi 123!';
		$archive  = $this->backup( $password );
		$bytes    = (string) file_get_contents( $archive );

		// Nothing readable about the site: no URL, table names or file names outside the encryption.
		$this->assertSame( 0, preg_match( '/example\.com|wp_options|astra|photo\.jpg/', $bytes ) );
		$this->assertStringStartsWith( 'backup-', basename( $archive ) );
		$fmw = new FmwArchive( $archive );
		$this->assertTrue( $fmw->encrypted() );
		$this->assertSame( 64, strlen( (string) $fmw->header()['manifest_hmac'] ) );
		$this->assertNotSame( str_repeat( '0', 64 ), $fmw->header()['manifest_hmac'] );

		// Wrong or missing password: refused before anything happens; verify checks SHA-256 and HMAC.
		try {
			$fmw->manifest( 'wrong password' );
			$this->fail( 'Expected a password error.' );
		} catch ( PasswordException $e ) {
			$this->assertTrue( $e->given );
		}
		$this->assertSame( array( 'This backup is encrypted; a password is required.' ), $fmw->verify() );
		$this->assertSame( array(), $fmw->verify( null, $password ) );

		// Parts decrypt with the stock OpenSSL command line (format v1, section 7).
		$openssl = $this->tool( 'openssl' );
		$tar     = $this->tool( 'tar' );
		if ( null !== $openssl && null !== $tar ) {
			$part = null;
			foreach ( $fmw->manifest( $password )['parts'] as $candidate ) {
				if ( 'files' === $candidate['type'] ) {
					$part = $candidate;
					break;
				}
			}
			$this->run_command( 'cd ' . escapeshellarg( $this->tmp ) . ' && tar -xf ' . escapeshellarg( $archive ) . ' ' . escapeshellarg( $part['path'] ) );
			list( $code ) = $this->run_command( 'openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -pass ' . escapeshellarg( 'pass:' . $password ) . ' -in ' . escapeshellarg( $this->tmp . '/' . $part['path'] ) . ' -out ' . escapeshellarg( $this->tmp . '/plain.tar' ) );
			$this->assertSame( 0, $code );
			$this->assertSame( (int) $part['bytes_plain'], filesize( $this->tmp . '/plain.tar' ) );
		}

		$store = new JobStore( $this->tmp . '/restore-jobs' );
		$wrong = $this->run_job( $store, 'restore', $this->restore_options( $archive, 'nope nope' ), self::TARGET );
		$this->assertSame( Job::STATUS_FAILED, $wrong->status );
		$this->assertStringContainsString( 'Wrong password', (string) $wrong->error );

		$job = $this->run_job( $store, 'restore', $this->restore_options( $archive, $password ), self::TARGET );
		$this->assertArrayNotHasKey( 'secret_password', $job->options );
		$this->assert_restored( $store, $job );
	}

	/**
	 * Checks the target site after a restore of the source site.
	 *
	 * @param JobStore $store Restore job store.
	 * @param Job      $job   Finished job.
	 * @return void
	 */
	private function assert_restored( JobStore $store, Job $job ): void {

		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		$db = $this->connect( self::TARGET );

		// URLs and paths, including serialized and JSON values, exactly once.
		$option = static function ( string $name ) use ( $db ) {
			return $db->column( 'SELECT option_value FROM shop_options WHERE option_name = ' . $db->quote( $name ) )[0] ?? null;
		};
		$this->assertSame( 'https://example.com/staging', $option( 'siteurl' ) );
		$this->assertSame( '/home/new/www/wp-content/uploads', $option( 'upload_path' ) );
		$this->assertSame( '{"logo":"https:\/\/example.com\/staging\/logo.png"}', $option( 'theme_mods' ) );
		$this->assertSame( '<a href="https://example.com/staging/promo">Promo ñ</a>', unserialize( (string) $option( 'widget_text' ) )[2]['text'] ); // phpcs:ignore
		$this->assertSame( 'admin@example.com', $option( 'admin_email' ), 'same host: e-mail unchanged' );
		$this->assertSame( 'See https://example.com/staging/page-7 and //example.com/staging/img-7.jpg', $db->column( 'SELECT post_content FROM shop_posts WHERE ID = 7' )[0] );
		$this->assertSame( 'https://example.com/?p=7', $db->column( 'SELECT guid FROM shop_posts WHERE ID = 7' )[0], 'guid is never changed' );
		$this->assertSame( '0', $db->column( "SELECT COUNT(*) FROM shop_posts WHERE post_content LIKE '%staging/staging%'" )[0] );

		// Prefix-based keys.
		$this->assertNull( $option( 'wp_user_roles' ) );
		$this->assertNotNull( $option( 'shop_user_roles' ) );
		$this->assertSame( array( 'nickname', 'shop_capabilities', 'shop_user_level' ), $db->column( 'SELECT meta_key FROM shop_usermeta ORDER BY meta_key' ) );

		// Tables: restored ones live, unrelated ones untouched, no leftovers.
		$tables = $db->column( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME" );
		$this->assertSame( array( 'other_site_posts', 'shop_extra', 'shop_options', 'shop_posts', 'shop_usermeta', 'shop_users' ), $tables );
		$this->assertSame( '42', $db->column( 'SELECT id FROM shop_extra' )[0] );
		$this->assertSame( '1200', $db->column( 'SELECT COUNT(*) FROM shop_posts' )[0] );

		// Views and triggers moved to the new prefix and working.
		$this->assertSame( '9', $db->column( 'SELECT COUNT(*) FROM shop_titles' )[0] );
		$this->assertSame( array( 'shop_posts_bi' ), $db->column( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()' ) );
		$this->assertSame( array( 'shop_posts' ), $db->column( 'SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()' ) );

		// This plugin stays active.
		$this->assertSame( array( self::PLUGIN, 'shop/shop.php' ), unserialize( (string) $option( 'active_plugins' ) ) ); // phpcs:ignore
		$db->close();

		// Files: restored, protected plugin folder untouched, escaping symlink skipped.
		$target = $this->tmp . '/target/wp-content';
		foreach ( array( 'themes/astra/style.css', 'uploads/2026/09/photo.jpg', 'plugins/shop/shop.php' ) as $file ) {
			$this->assertSame( sha1_file( $this->tmp . '/source/wp-content/' . $file ), sha1_file( $target . '/' . $file ), $file );
		}
		$this->assertSame( '<?php // running FMW', file_get_contents( $target . '/plugins/founders-migration-website/founders-migration-website.php' ) );
		$this->assertFileDoesNotExist( $target . '/plugins/founders-migration-website/old-fmw.php' );
		$this->assertFalse( is_link( $target . '/uploads/etc-link' ) );
		$this->assertStringContainsString( 'Skipped symlink uploads/etc-link -> /etc', implode( "\n", $store->log_lines( $job->id, 0 ) ) );
	}

	public function test_a_subdomain_network_moves_to_a_new_domain_with_its_subsites(): void {
		$db = $this->connect( self::SOURCE );
		$db->query( 'CREATE TABLE wp_blogs (blog_id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id bigint NOT NULL, domain varchar(200) NOT NULL, path varchar(100) NOT NULL)' );
		$db->query( "INSERT INTO wp_blogs VALUES (1, 1, 'example.com', '/'), (2, 1, 'shop.example.com', '/'), (3, 1, 'brand.example', '/'), (4, 1, 'example.com.au', '/')" );
		$db->query( 'CREATE TABLE wp_site (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, domain varchar(200) NOT NULL, path varchar(100) NOT NULL)' );
		$db->query( "INSERT INTO wp_site VALUES (1, 'example.com', '/')" );
		$db->query( 'CREATE TABLE wp_sitemeta (meta_id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id bigint NOT NULL, meta_key varchar(255), meta_value longtext)' );
		$db->query( "INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1, 'siteurl', 'https://example.com/'), (1, 'active_sitewide_plugins', 'a:0:{}')" );
		foreach ( array(
			2 => 'https://shop.example.com',
			3 => 'https://brand.example',
			4 => 'https://example.com.au',
		) as $id => $url ) {
			$db->query( "CREATE TABLE wp_{$id}_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL)" );
			$db->query( "INSERT INTO wp_{$id}_options (option_name, option_value) VALUES ('home', '{$url}'), ('siteurl', '{$url}'), ('wp_{$id}_user_roles', 'a:0:{}'), ('promo', " . $db->quote( serialize( array( 'link' => $url . '/deal' ) ) ) . ')' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}
		$db->query( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_2_capabilities', 'a:1:{s:6:\"editor\";b:1;}')" );
		$db->close();

		$db = $this->connect( self::TARGET );
		$db->query( 'CREATE TABLE shop_blogs (blog_id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id bigint NOT NULL, domain varchar(200) NOT NULL, path varchar(100) NOT NULL)' );
		$db->query( "INSERT INTO shop_blogs VALUES (1, 1, 'staging.test', '/'), (2, 1, 'two.staging.test', '/'), (7, 1, 'seven.staging.test', '/')" );
		$db->query( 'CREATE TABLE shop_2024_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL)' ); // Another install, same database.
		foreach ( array( 2, 7 ) as $id ) { // Site 7 of this network is not in the backup.
			$db->query( "CREATE TABLE shop_{$id}_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL)" );
			$db->query( "CREATE TABLE shop_{$id}_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title text)" );
			$db->query( "INSERT INTO shop_{$id}_posts (post_title) VALUES ('target site {$id}')" );
		}
		$db->close();

		$archive                        = $this->backup(
			'',
			array(
				'multisite' => true,
				'sites'     => array(
					array( 'blog_id' => 1, 'domain' => 'example.com', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 2, 'domain' => 'shop.example.com', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 3, 'domain' => 'brand.example', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 4, 'domain' => 'example.com.au', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
				),
				'network'   => array(
					'domain'    => 'example.com',
					'path'      => '/',
					'subdomain' => true,
					'main_site' => 1,
				),
			)
		);
		$options                        = $this->restore_options( $archive );
		$options['target']['home_url']  = 'https://staging.test';
		$options['target']['site_url']  = 'https://staging.test';
		$options['target']['multisite'] = true;
		$options['target']['network']   = array(
			'domain'    => 'staging.test',
			'path'      => '/',
			'subdomain' => true,
			'main_site' => 1,
		);
		$options['domain_map']          = array( 'brand.example' => 'brand.staging.test' );
		$options['keep_active_network'] = true;
		$store                          = new JobStore( $this->tmp . '/restore-jobs' );
		$job                            = $this->run_job( $store, 'restore', $options, self::TARGET );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );

		$db = $this->connect( self::TARGET );
		$this->assertSame(
			array( '1 staging.test /', '2 shop.staging.test /', '3 brand.staging.test /', '4 example.com.au /' ),
			$db->column( "SELECT CONCAT(blog_id, ' ', domain, ' ', path) FROM shop_blogs ORDER BY blog_id" )
		);
		$this->assertSame( array( 'staging.test /' ), $db->column( "SELECT CONCAT(domain, ' ', path) FROM shop_site" ) );
		$this->assertSame( array( 'https://staging.test/' ), $db->column( "SELECT meta_value FROM shop_sitemeta WHERE meta_key = 'siteurl'" ) );
		$this->assertSame( array( 'https://shop.staging.test' ), $db->column( "SELECT option_value FROM shop_2_options WHERE option_name = 'home'" ) );
		$this->assertSame( array( 'https://brand.staging.test' ), $db->column( "SELECT option_value FROM shop_3_options WHERE option_name = 'home'" ) );
		// A kept domain that starts with the network's domain stays as it is.
		$this->assertSame( array( 'https://example.com.au' ), $db->column( "SELECT option_value FROM shop_4_options WHERE option_name = 'home'" ) );
		$this->assertSame( array( serialize( array( 'link' => 'https://example.com.au/deal' ) ) ), $db->column( "SELECT option_value FROM shop_4_options WHERE option_name = 'promo'" ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( array( 'shop_2024_options' ), $db->column( "SHOW TABLES LIKE 'shop\\_2024%'" ) );
		$this->assertSame( array( serialize( array( 'link' => 'https://shop.staging.test/deal' ) ) ), $db->column( "SELECT option_value FROM shop_2_options WHERE option_name = 'promo'" ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( array( 'shop_2_user_roles' ), $db->column( "SELECT option_name FROM shop_2_options WHERE option_name LIKE '%user_roles'" ) );
		$this->assertSame( array( 'shop_2_capabilities' ), $db->column( "SELECT meta_key FROM shop_usermeta WHERE meta_key LIKE '%2_capabilities'" ) );
		$sitewide = unserialize( (string) $db->column( "SELECT meta_value FROM shop_sitemeta WHERE meta_key = 'active_sitewide_plugins'" )[0] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$this->assertArrayHasKey( self::PLUGIN, $sitewide );
		// Site 7 is not in the backup: its tables went aside with the replaced ones and were removed at the end.
		$this->assertSame( array(), $db->column( "SHOW TABLES LIKE 'shop\\_7\\_%'" ) );
		$this->assertSame( array(), $db->column( "SHOW TABLES LIKE 'fmw%'" ) );
		$this->assertSame( array( 'Post 1' ), $db->column( 'SELECT post_title FROM shop_posts WHERE ID = 1' ) );
		$db->close();

		$log = implode( "\n", $store->log_lines( $job->id, 200 ) );
		$this->assertStringContainsString( 'Site 3: brand.example/ -> brand.staging.test/', $log );
		$this->assertStringContainsString( 'Site 4: example.com.au/ (own domain, kept', $log );
		$this->assertStringContainsString( 'Moved the network to staging.test/ (3 sites).', $log );
	}

	public function test_a_network_is_not_restored_onto_a_single_site(): void {
		$archive = $this->backup( '', array( 'multisite' => true, 'sites' => array( array( 'blog_id' => 1, 'domain' => 'example.com', 'path' => '/' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$job     = $this->run_job( new JobStore( $this->tmp . '/restore-jobs' ), 'restore', $this->restore_options( $archive ), self::TARGET );
		$this->assertSame( Job::STATUS_FAILED, $job->status );
		$this->assertStringContainsString( 'choose the site to restore', (string) $job->error );
	}

	public function test_one_site_of_a_network_backup_becomes_a_single_site(): void {
		$db = $this->connect( self::SOURCE );
		$db->query( 'CREATE TABLE wp_blogs (blog_id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id bigint NOT NULL, domain varchar(200) NOT NULL, path varchar(100) NOT NULL)' );
		$db->query( "INSERT INTO wp_blogs VALUES (1, 1, 'example.com', '/'), (2, 1, 'example.com', '/shop/'), (3, 1, 'example.com', '/news/')" );
		$db->query( 'CREATE TABLE wp_sitemeta (meta_id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id bigint NOT NULL, meta_key varchar(255), meta_value longtext)' );
		$db->query( "INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1, 'site_admins', " . $db->quote( serialize( array( 'admin' ) ) ) . "), (1, 'active_sitewide_plugins', " . $db->quote( serialize( array( 'net/net.php' => 1 ) ) ) . ')' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		foreach ( array( 2, 3 ) as $id ) {
			$db->query( "CREATE TABLE wp_{$id}_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL)" );
			$db->query( "CREATE TABLE wp_{$id}_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_author bigint unsigned NOT NULL DEFAULT 0, guid varchar(255) NOT NULL, post_title text, post_content longtext)" );
		}
		$shop = 'https://example.com/shop';
		$db->query( "INSERT INTO wp_2_options (option_name, option_value) VALUES ('home', '{$shop}'), ('siteurl', '{$shop}'), ('wp_2_user_roles', 'a:1:{s:6:\"editor\";a:0:{}}'), ('active_plugins', " . $db->quote( serialize( array( 'shop/shop.php' ) ) ) . ')' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$db->query( "INSERT INTO wp_2_posts (post_author, guid, post_title, post_content) VALUES (2, '{$shop}/?p=1', 'Shop post', " . $db->quote( "<img src=\"https://example.com/wp-content/uploads/sites/2/2026/09/shop.jpg\"> <img src=\"{$shop}/wp-content/uploads/sites/2/2026/09/shop.jpg\"> <a href=\"{$shop}/cart\">cart</a> <a href=\"https://example.com/news/x\">news</a> <a href=\"https://example.com/about\">main</a> <a href=\"https://example.com/shopping-guide/\">guide</a> /home/old/public_html/wp-content/uploads/sites/2/x" ) . ')' );
		$db->query( 'CREATE TABLE wp_2_comments (comment_ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint unsigned NOT NULL DEFAULT 0, comment_content text)' );
		$db->query( "INSERT INTO wp_2_comments (user_id, comment_content) VALUES (3, 'Nice shop')" );
		$db->query( "INSERT INTO wp_3_posts (post_author, guid, post_title, post_content) VALUES (3, 'https://example.com/news/?p=1', 'News post', 'x')" );
		$db->query( "INSERT INTO wp_users (user_login, user_email) VALUES ('editor2', 'editor2@example.com'), ('newsonly', 'news@example.com')" );
		$db->query( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (2, 'wp_2_capabilities', 'a:1:{s:6:\"editor\";b:1;}'), (2, 'wp_2_user_level', '7'), (2, 'primary_blog', '2'), (2, 'wp_3_capabilities', 'a:0:{}'), (3, 'wp_3_capabilities', 'a:1:{s:6:\"author\";b:1;}'), (2, 'wp_2fa_enabled', '1')" );
		$db->close();
		$this->make_file( 'source/wp-content/uploads/sites/2/2026/09/shop.jpg', 'shop photo' );
		$this->make_file( 'source/wp-content/uploads/sites/3/2026/09/news.jpg', 'news photo' );

		$archive = $this->backup(
			'',
			array(
				'multisite'   => true,
				'uploads_dir' => 'wp-content/uploads',
				'content_dir' => 'wp-content',
				'sites'       => array(
					array( 'blog_id' => 1, 'domain' => 'example.com', 'path' => '/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 2, 'domain' => 'example.com', 'path' => '/shop/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
					array( 'blog_id' => 3, 'domain' => 'example.com', 'path' => '/news/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
				),
				'network'     => array(
					'domain'    => 'example.com',
					'path'      => '/',
					'subdomain' => false,
					'main_site' => 1,
				),
			)
		);
		$options                          = $this->restore_options( $archive );
		$options['subsite']               = 'https://example.com/shop/';
		$options['target']['uploads_dir'] = $this->tmp . '/target/wp-content/uploads';
		$options['target']['uploads_url'] = 'https://example.com/staging/wp-content/uploads';
		$job                              = $this->run_job( new JobStore( $this->tmp . '/restore-jobs' ), 'restore', $options, self::TARGET );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );

		$db     = $this->connect( self::TARGET );
		$tables = $db->column( 'SHOW TABLES' );
		$this->assertSame( array( 'other_site_posts', 'shop_comments', 'shop_extra', 'shop_options', 'shop_posts', 'shop_usermeta', 'shop_users' ), $tables ); // No network tables, no other site's.
		$this->assertSame( array( 'Shop post' ), $db->column( 'SELECT post_title FROM shop_posts' ) );
		$this->assertSame(
			'<img src="https://example.com/staging/wp-content/uploads/2026/09/shop.jpg"> <img src="https://example.com/staging/wp-content/uploads/2026/09/shop.jpg"> <a href="https://example.com/staging/cart">cart</a> <a href="https://example.com/news/x">news</a> <a href="https://example.com/about">main</a> <a href="https://example.com/shopping-guide/">guide</a> ' . $this->tmp . '/target/wp-content/uploads/x',
			(string) $db->column( 'SELECT post_content FROM shop_posts' )[0]
		);
		$this->assertSame( array( 'https://example.com/staging' ), $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'home'" ) );
		$this->assertSame( array( 'shop_user_roles' ), $db->column( "SELECT option_name FROM shop_options WHERE option_name LIKE '%user_roles'" ) );
		$plugins = unserialize( (string) $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'active_plugins'" )[0] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$this->assertSame( array( self::PLUGIN, 'net/net.php', 'shop/shop.php' ), $plugins );
		// Users with a role or posts here stay, super admins become administrators, the rest stays out.
		$this->assertSame( array( 'admin', 'editor2', 'newsonly' ), $db->column( 'SELECT user_login FROM shop_users ORDER BY ID' ) ); // newsonly commented here.
		$this->assertSame( array( 'admin@example.com', 'editor2@example.com', 'news@example.com' ), $db->column( 'SELECT user_email FROM shop_users ORDER BY ID' ) ); // The network's e-mail domain stays.
		$this->assertSame( array( '1' ), $db->column( "SELECT meta_value FROM shop_usermeta WHERE meta_key = 'shop_2fa_enabled'" ) ); // Global key with the bare prefix: kept (and moved to the target prefix).
		$this->assertSame(
			array( '1 shop_capabilities a:1:{s:13:"administrator";b:1;}', '1 shop_user_level 10', '2 shop_2fa_enabled 1', '2 shop_capabilities a:1:{s:6:"editor";b:1;}', '2 shop_user_level 7' ),
			$db->column( "SELECT CONCAT(user_id, ' ', meta_key, ' ', meta_value) FROM shop_usermeta WHERE meta_key LIKE 'shop\\_%' ORDER BY user_id, meta_key" )
		);
		$this->assertSame( array(), $db->column( "SELECT meta_key FROM shop_usermeta WHERE meta_key LIKE 'wp\\_%' OR meta_key = 'primary_blog'" ) );
		$db->close();

		$content = $this->tmp . '/target/wp-content';
		$this->assertSame( 'shop photo', file_get_contents( $content . '/uploads/2026/09/shop.jpg' ) );
		$this->assertFileDoesNotExist( $content . '/uploads/2026/09/photo.jpg' ); // Site 1's media.
		$this->assertFileDoesNotExist( $content . '/uploads/sites' );
		$this->assertFileExists( $content . '/themes/astra/style.css' );
	}

	public function test_a_damaged_archive_fails_before_the_live_site_changes(): void {
		$archive = $this->backup();

		// Damage the first database part inside the archive.
		list( , $blocks ) = $this->run_command( 'tar -tRf ' . escapeshellarg( $archive ) );
		preg_match( '/^block (\d+): database\//m', $blocks, $m );
		$handle = fopen( $archive, 'r+b' );
		fseek( $handle, ( (int) $m[1] + 1 ) * 512 + 20 );
		fwrite( $handle, 'XXXX' );
		fclose( $handle );

		$db     = $this->connect( self::TARGET );
		$before = $db->rows( 'CHECKSUM TABLE shop_options, shop_extra' );

		$job = $this->run_job( new JobStore( $this->tmp . '/restore-jobs' ), 'restore', $this->restore_options( $archive ), self::TARGET );

		$this->assertSame( Job::STATUS_FAILED, $job->status );
		$this->assertStringContainsString( 'SHA-256 mismatch', (string) $job->error );
		$this->assertSame( $before, $db->rows( 'CHECKSUM TABLE shop_options, shop_extra' ) );
		$this->assertSame( 'https://old-target.test', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'siteurl'" )[0] );
		$db->close();
	}

	public function test_hostile_sql_in_an_archive_is_refused(): void {
		$sql  = $this->tmp . '/evil.sql.gz';
		$sink = new GzipFileSink( $sql );
		$sink->write( "SET NAMES utf8mb4;\nCREATE TABLE `wp_evil` (id int) ENGINE=InnoDB;\nINSERT INTO `wp_evil` VALUES ((SELECT COUNT(*) FROM mysql.user));\n" );
		$sink->close();

		$part     = array(
			'path'        => 'database/0001-wp_evil.0001.sql.gz',
			'type'        => 'database',
			'table'       => 'wp_evil',
			'chunk'       => 1,
			'rows'        => 1,
			'compression' => 'gzip',
			'bytes_raw'   => 0,
			'bytes'       => filesize( $sql ),
			'sha256'      => hash_file( 'sha256', $sql ),
		);
		$archive  = $this->tmp . '/evil.fmw';
		$writer   = new TarWriter( new PlainFileSink( $archive ) );
		$manifest = array(
			'format'  => 'fmw',
			'version' => 1,
			'site'    => array(
				'home_url'     => 'https://example.com',
				'table_prefix' => 'wp_',
			),
			'parts'   => array( $part ),
		);
		$writer->add_string( 'fmw.json', '{"format":"fmw","version":1,"encrypted":false}' );
		$writer->add_file( $sql, \Founders\Migration\Archive\TarEntry::file( $part['path'], $part['bytes'] ) );
		$writer->add_string( 'manifest.json', (string) json_encode( $manifest ) );
		$writer->finish();

		$job = $this->run_job( new JobStore( $this->tmp . '/restore-jobs' ), 'restore', $this->restore_options( $archive ), self::TARGET );

		$this->assertSame( Job::STATUS_FAILED, $job->status );
		$this->assertStringContainsString( 'INSERT values must be literals', (string) $job->error );
		$db = $this->connect( self::TARGET );
		$this->assertSame( 'https://old-target.test', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'siteurl'" )[0] );
		$this->assertSame( array(), $db->column( "SHOW TABLES LIKE 'shop_evil'" ) );
		$db->close();
	}

	public function test_a_part_name_that_leaves_the_staging_folder_is_refused(): void {
		$archive  = $this->tmp . '/escape.fmw';
		$writer   = new TarWriter( new PlainFileSink( $archive ) );
		$part     = array(
			'path'        => 'database/../../../escape.php',
			'type'        => 'database',
			'table'       => 'views',
			'compression' => 'none',
			'bytes_raw'   => 5,
			'bytes'       => 5,
			'sha256'      => hash( 'sha256', '<?php' ),
		);
		$manifest = array(
			'format'  => 'fmw',
			'version' => 1,
			'site'    => array( 'home_url' => 'https://example.com', 'table_prefix' => 'wp_' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
			'parts'   => array( $part ),
		);
		$writer->add_string( 'fmw.json', '{"format":"fmw","version":1,"encrypted":false}' );
		$writer->add_string( $part['path'], '<?php' );
		$writer->add_string( 'manifest.json', (string) json_encode( $manifest ) );
		$writer->finish();

		$job = $this->run_job( new JobStore( $this->tmp . '/restore-jobs' ), 'restore', $this->restore_options( $archive ), self::TARGET );
		$this->assertSame( Job::STATUS_FAILED, $job->status );
		$this->assertStringContainsString( 'Unsafe part name', (string) $job->error );
		$this->assertFileDoesNotExist( $this->tmp . '/escape.php' );
	}
}
