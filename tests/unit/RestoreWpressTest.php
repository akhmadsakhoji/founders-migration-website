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

use Founders\Migration\Archive\WpressPackage;
use Founders\Migration\Database\Connection;
use Founders\Migration\Database\DatabaseException;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\StepRegistry;
use Founders\Migration\Model\Import\FinalizeStep;
use Founders\Migration\Model\Import\ReplaceStep;
use Founders\Migration\Model\Import\SwapStep;
use Founders\Migration\Model\Import\WpressCheckStep;
use Founders\Migration\Model\Import\WpressDatabaseStep;
use Founders\Migration\Model\Import\WpressFilesStep;
use Founders\Migration\Tests\TestCase;
use Founders\Migration\Tests\WpressBuilder;

require_once dirname( __DIR__ ) . '/WpressBuilder.php';

/**
 * Restores All-in-One WP Migration archives (written like the plugin writes them) onto a site with another URL, path and prefix.
 *
 * Needs a MySQL/MariaDB server (see DatabaseDumpTest); skipped otherwise.
 */
final class RestoreWpressTest extends TestCase {

	const TARGET   = 'fmwp_wpress_dst';
	const PLUGIN   = 'founders-migration-website/founders-migration-website.php';
	const PASSWORD = 'Rahasia 123';

	/**
	 * Server settings.
	 *
	 * @var array{host:string,user:string,pass:string}
	 */
	private $server;

	/**
	 * Registry.
	 *
	 * @var StepRegistry
	 */
	private $registry;

	/**
	 * Files in the archive (relative to wp-content).
	 *
	 * @var array<string,string>
	 */
	private $files;

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
		$admin->query( 'DROP DATABASE IF EXISTS ' . self::TARGET );
		$admin->query( 'CREATE DATABASE ' . self::TARGET . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$admin->close();

		Connection::set_factory(
			function () {
				return $this->connect( self::TARGET );
			}
		);
		$this->registry = new StepRegistry();
		$this->registry->register( 'restore-wpress', array( WpressCheckStep::class, WpressDatabaseStep::class, ReplaceStep::class, WpressFilesStep::class, SwapStep::class, FinalizeStep::class ) );

		$this->files = array(
			'index.php'                                  => '<?php // Silence is golden.',
			'uploads/2026/09/photo.jpg'                  => random_bytes( 1100000 ),
			'uploads/2026/09/notes.txt'                  => 'See https://old.example/blog/about',
			'themes/astra/style.css'                     => str_repeat( "body{}\n", 3000 ),
			'plugins/shop/shop.php'                      => '<?php // shop',
			'plugins/demo/package.json'                  => '{"name":"demo"}',
			'plugins/founders-migration-website/old.php' => '<?php // an older FMW inside the backup',
			'fmw-backups/old-site.fmw'                   => 'not restored',
		);

		// Target site: other URL, path and prefix; live tables that must end up replaced or kept.
		$this->make_file( 'target/wp-content/themes/astra/style.css', 'old theme' );
		$this->make_file( 'target/wp-content/plugins/founders-migration-website/founders-migration-website.php', '<?php // running FMW' );
		$db = $this->connect( self::TARGET );
		$db->query( 'CREATE TABLE shop_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL)' );
		$db->query( "INSERT INTO shop_options (option_name, option_value) VALUES ('siteurl', 'https://old-target.test')" );
		$db->query( 'CREATE TABLE other_site_posts (id int PRIMARY KEY)' );
		$db->close();
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
	 * A database.sql as All-in-One WP Migration writes it: SERVMASK_PREFIX_ tables and prefix-based keys, transactions, a view.
	 *
	 * @return string
	 */
	private static function dump(): string {
		$url   = 'https://old.example/blog';
		$roles = serialize( array( 'administrator' => array( 'name' => 'Administrator' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test data.
		$text  = serialize( array( 'text' => "<a href=\"{$url}/promo\">Promo</a>" ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test data.
		$q     = static function ( string $value ): string {
			return "'" . addslashes( $value ) . "'";
		};
		return "-- All-in-One WP Migration SQL Dump\n-- https://servmask.com/\n--\n\n"
			. "DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;\n"
			. "CREATE TABLE `SERVMASK_PREFIX_options` (\n  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `option_name` varchar(191) NOT NULL DEFAULT '',\n  `option_value` longtext NOT NULL,\n  `autoload` varchar(20) NOT NULL DEFAULT 'yes',\n  PRIMARY KEY (`option_id`),\n  UNIQUE KEY `option_name` (`option_name`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n"
			. "START TRANSACTION;\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'siteurl'," . $q( $url ) . ",'on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (2,'home'," . $q( $url ) . ",'on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (3,'SERVMASK_PREFIX_user_roles'," . $q( $roles ) . ",'on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (4,'SERVMASK_PREFIX_custom_setting','kept','on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (5,'widget_text'," . $q( $text ) . ",'on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (6,'upload_path','/srv/old/wp-content/uploads','on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (7,'admin_email','admin@old.example','on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (8,'active_plugins','a:0:{}','on');\n" // Blanked by the export; package.json lists them.
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (10,'template','','on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (11,'stylesheet','','on');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (9,'brand','Old Brand Ltd','on');\n"
			. "COMMIT;\n\n"
			. "DROP TABLE IF EXISTS `SERVMASK_PREFIX_posts`;\n"
			. "CREATE TABLE `SERVMASK_PREFIX_posts` (`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `guid` varchar(255) NOT NULL DEFAULT '', `post_title` text NOT NULL, `post_content` longtext NOT NULL, `post_status` varchar(20) NOT NULL DEFAULT 'publish', `thumb` longblob, PRIMARY KEY (`ID`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n"
			. "START TRANSACTION;\n"
			. "INSERT INTO `SERVMASK_PREFIX_posts` VALUES (1,'{$url}/?p=1','Hello','<img src=\\\"{$url}/wp-content/uploads/2026/09/photo.jpg\\\"> it\\'s','publish',0x00ff10);\n"
			. "INSERT INTO `SERVMASK_PREFIX_posts` VALUES (2,'{$url}/?p=2','Draft','x','draft',NULL);\n"
			. "COMMIT;\n\n"
			. "DROP TABLE IF EXISTS `SERVMASK_PREFIX_usermeta`;\n"
			. "CREATE TABLE `SERVMASK_PREFIX_usermeta` (`umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint(20) unsigned NOT NULL DEFAULT 0, `meta_key` varchar(255) DEFAULT NULL, `meta_value` longtext, PRIMARY KEY (`umeta_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n"
			. "START TRANSACTION;\n"
			. "INSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (1,1,'SERVMASK_PREFIX_capabilities','a:1:{s:13:\\\"administrator\\\";b:1;}');\n"
			. "INSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (2,1,'nickname','admin');\n"
			. "COMMIT;\n\n"
			. "DROP VIEW IF EXISTS `SERVMASK_PREFIX_published`;\n"
			. "CREATE VIEW `SERVMASK_PREFIX_published` AS select `SERVMASK_PREFIX_posts`.`ID` AS `ID` from `SERVMASK_PREFIX_posts` where `SERVMASK_PREFIX_posts`.`post_status` = 'publish';\n\n";
	}

	/**
	 * package.json of the source site.
	 *
	 * @param bool $encrypted Encrypted.
	 * @param bool $gzip      Compressed.
	 * @return string
	 */
	private static function package( bool $encrypted, bool $gzip ): string {
		$data = array(
			'SiteURL'   => 'https://old.example/blog',
			'HomeURL'   => 'https://old.example/blog',
			'Replace'   => array(
				'OldValues' => array( 'Old Brand Ltd' ),
				'NewValues' => array( 'New Brand Ltd' ),
			),
			'Plugin'    => array( 'Version' => $gzip ? '7.111' : '7.85' ),
			'WordPress' => array(
				'Version'    => '6.8',
				'Absolute'   => '/srv/old/',
				'Content'    => '/srv/old/wp-content',
				'Uploads'    => '/srv/old/wp-content/uploads',
				'UploadsURL' => 'https://old.example/blog/wp-content/uploads/',
			),
			'Database'  => array( 'Prefix' => 'wp_' ),
			'Plugins'    => array( 'shop/shop.php', 'wps-hide-login/wps-hide-login.php', 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ),
			'Template'   => 'astra',
			'Stylesheet' => 'astra-child',
		);
		if ( $encrypted ) {
			$data['Encrypted'] = true;
		}
		if ( $gzip ) {
			$data['Compression'] = array(
				'Enabled' => true,
				'Type'    => 'gzip',
			);
		}
		return (string) json_encode( $data );
	}

	/**
	 * Builds an archive.
	 *
	 * @param bool $encrypted Encrypted.
	 * @param bool $gzip      Compressed (and CRC-carrying, like recent versions); otherwise like 7.85.
	 * @return string
	 */
	private function archive( bool $encrypted, bool $gzip ): string {
		$builder = new WpressBuilder( $encrypted ? self::PASSWORD : null, $gzip ? 'gzip' : 'none', $gzip );
		$builder->add( 'package.json', self::package( $encrypted, $gzip ) );
		foreach ( $this->files as $path => $content ) {
			// Older versions left every file named package.json unencrypted.
			$builder->add( $path, $content, 'plugins/demo/package.json' === $path && ! $gzip ? false : null );
		}
		$builder->add( 'database.sql', self::dump() );
		return $builder->save( $this->tmp . '/site-' . ( $encrypted ? 'enc' : 'plain' ) . ( $gzip ? '-gzip' : '' ) . '.wpress' );
	}

	/**
	 * Restore options for the target site.
	 *
	 * @param string $archive   Archive.
	 * @param bool   $encrypted Add the key.
	 * @return array<string,mixed>
	 */
	private function options( string $archive, bool $encrypted ): array {
		$content = $this->tmp . '/target/wp-content';
		$options = array(
			'archive'            => $archive,
			'target'             => array(
				'home_url'       => 'https://new.example',
				'site_url'       => 'https://new.example',
				'abspath'        => '/home/new/www/',
				'content_dir'    => $content,
				'uploads_dir'    => $content . '/uploads',
				'uploads_url'    => 'https://new.example/wp-content/uploads',
				'plugins_dir'    => $content . '/plugins',
				'mu_plugins_dir' => $content . '/mu-plugins',
				'themes_dir'     => $content . '/themes',
				'table_prefix'   => 'shop_',
				'multisite'      => false,
			),
			'protect_paths'      => array( 'plugins/founders-migration-website', 'fmw-backups', 'fmw-storage' ),
			'email_replace'      => true,
			'keep_active_plugin' => self::PLUGIN,
			'skip_space_check'   => true,
		);
		if ( $encrypted ) {
			$options['secret_wpress_key'] = Secrets::seal( WpressPackage::derive_key( self::PASSWORD ) );
		}
		return $options;
	}

	/**
	 * Runs the job to the end, one fresh "process" per slice.
	 *
	 * @param array<string,mixed> $options Options.
	 * @return Job
	 */
	private function run_job( array $options ): Job {
		$store = new JobStore( $this->tmp . '/jobs' );
		$job   = $store->create( 'restore-wpress', $options );
		for ( $i = 0; $i < 20000; $i++ ) {
			$job = ( new Runner( $store, $this->registry, null, 0.001 ) )->run( $store->load( $job->id ), new Deadline( 0.0 ) );
			if ( Job::STATUS_RUNNING !== $job->status ) {
				return $job;
			}
		}
		$this->fail( 'Job did not finish.' );
	}

	/**
	 * @return array<string,array{0:bool,1:bool}>
	 */
	public function variants(): array {
		return array(
			'7.85 style, plain'              => array( false, false ),
			'7.85 style, encrypted'          => array( true, false ),
			'7.111 style, gzip + encrypted'  => array( true, true ),
		);
	}

	/**
	 * @dataProvider variants
	 *
	 * @param bool $encrypted Encrypted.
	 * @param bool $gzip      Compressed, with CRCs.
	 */
	public function test_restores_onto_a_new_url_path_and_prefix_resuming_after_every_slice( bool $encrypted, bool $gzip ): void {
		$job = $this->run_job( $this->options( $this->archive( $encrypted, $gzip ), $encrypted ) );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		$this->assertArrayNotHasKey( 'secret_wpress_key', $job->options );

		$db     = $this->connect( self::TARGET );
		$option = static function ( string $name ) use ( $db ): ?string {
			$rows = $db->column( 'SELECT option_value FROM shop_options WHERE option_name = ' . $db->quote( $name ) );
			return isset( $rows[0] ) ? (string) $rows[0] : null;
		};

		$this->assertSame( array( 'other_site_posts', 'shop_options', 'shop_posts', 'shop_published', 'shop_usermeta' ), $db->column( 'SHOW TABLES' ) );
		$this->assertSame( 'https://new.example', $option( 'siteurl' ) );
		$this->assertSame( 'https://new.example', $option( 'home' ) );
		$this->assertSame( $this->tmp . '/target/wp-content/uploads', $option( 'upload_path' ) ); // Longest known path wins: the uploads folder.
		$this->assertSame( 'admin@new.example', $option( 'admin_email' ) );
		$this->assertSame( 'New Brand Ltd', $option( 'brand' ) ); // Find / replace chosen at export.
		$this->assertStringContainsString( 's:45:"<a href="https://new.example/promo">Promo</a>"', (string) $option( 'widget_text' ) );
		$this->assertNotNull( $option( 'shop_user_roles' ) );
		$this->assertNull( $option( 'SERVMASK_PREFIX_user_roles' ) );
		$this->assertSame( 'kept', $option( 'wp_custom_setting' ) ); // Original name, not the target prefix.
		// The plugins and theme package.json lists are active again; a login hider stays off; HTTPS plugins stay on an https:// site.
		$this->assertSame( array( 'founders-migration-website/founders-migration-website.php', 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'shop/shop.php' ), (array) unserialize( (string) $option( 'active_plugins' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test.
		$this->assertSame( 'astra', $option( 'template' ) );
		$this->assertSame( 'astra-child', $option( 'stylesheet' ) );

		$this->assertSame( array( 'shop_capabilities', 'nickname' ), $db->column( 'SELECT meta_key FROM shop_usermeta ORDER BY umeta_id' ) );
		$post = $db->rows( 'SELECT guid, post_content, HEX(thumb) AS thumb FROM shop_posts WHERE ID = 1' )[0];
		$this->assertSame( 'https://old.example/blog/?p=1', $post['guid'] );
		$this->assertSame( '<img src="https://new.example/wp-content/uploads/2026/09/photo.jpg"> it\'s', $post['post_content'] );
		$this->assertSame( '00FF10', $post['thumb'] );
		$this->assertSame( array( '1' ), $db->column( 'SELECT ID FROM shop_published' ) );
		$db->close();

		$content = $this->tmp . '/target/wp-content';
		foreach ( $this->files as $path => $expected ) {
			if ( 0 === strpos( $path, 'plugins/founders-migration-website/' ) || 0 === strpos( $path, 'fmw-backups/' ) ) {
				$this->assertFileDoesNotExist( $content . '/' . $path );
				continue;
			}
			$this->assertSame( $expected, file_get_contents( $content . '/' . $path ), $path );
		}
		$this->assertSame( 1700000000, filemtime( $content . '/uploads/2026/09/photo.jpg' ) );
		$this->assertSame( '<?php // running FMW', file_get_contents( $content . '/plugins/founders-migration-website/founders-migration-website.php' ) );
		$this->assertFileDoesNotExist( $content . '/package.json' );
		$this->assertFileDoesNotExist( $content . '/database.sql' );
	}

	public function test_a_network_backup_restores_onto_a_network_at_another_address(): void {
		$q     = static function ( string $value ): string {
			return "'" . addslashes( $value ) . "'";
		};
		$table = static function ( string $name, string $columns ): string {
			return "CREATE TABLE `SERVMASK_PREFIX_{$name}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
		};
		$opts  = '`option_id` bigint unsigned NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL, `option_value` longtext NOT NULL, `autoload` varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY (`option_id`), UNIQUE KEY `option_name` (`option_name`)';
		$sql   = $table( 'options', $opts ) . $table( '2_options', $opts ) . $table( '3_options', $opts )
			. $table( 'blogs', '`blog_id` bigint NOT NULL AUTO_INCREMENT, `site_id` bigint NOT NULL, `domain` varchar(200) NOT NULL, `path` varchar(100) NOT NULL, PRIMARY KEY (`blog_id`)' )
			. $table( 'site', '`id` bigint NOT NULL AUTO_INCREMENT, `domain` varchar(200) NOT NULL, `path` varchar(100) NOT NULL, PRIMARY KEY (`id`)' )
			. $table( 'sitemeta', '`meta_id` bigint NOT NULL AUTO_INCREMENT, `site_id` bigint NOT NULL, `meta_key` varchar(255), `meta_value` longtext, PRIMARY KEY (`meta_id`)' )
			. $table( 'usermeta', '`umeta_id` bigint unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint unsigned NOT NULL, `meta_key` varchar(255), `meta_value` longtext, PRIMARY KEY (`umeta_id`)' )
			. "INSERT INTO `SERVMASK_PREFIX_blogs` VALUES (1,1,'old.example','/'),(2,1,'shop.old.example','/'),(3,1,'brand.example','/');\n"
			. "INSERT INTO `SERVMASK_PREFIX_site` VALUES (1,'old.example','/');\n"
			. "INSERT INTO `SERVMASK_PREFIX_sitemeta` VALUES (1,1,'siteurl','https://old.example/');\n"
			. "INSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (1,1,'SERVMASK_PREFIX_capabilities','a:0:{}'),(2,1,'SERVMASK_PREFIX_2_capabilities','a:0:{}');\n";
		foreach ( array( '' => 'https://old.example', '2_' => 'https://shop.old.example', '3_' => 'https://brand.example' ) as $id => $url ) {
			$sql .= "INSERT INTO `SERVMASK_PREFIX_{$id}options` (`option_name`, `option_value`) VALUES ('home'," . $q( $url ) . "),('siteurl'," . $q( $url ) . "),('SERVMASK_PREFIX_{$id}user_roles','a:0:{}'),('active_plugins','a:0:{}'),('template',''),('stylesheet',''),('note'," . $q( "See {$url}/deal and mail@old.example" ) . ");\n";
		}
		$site      = static function ( int $id, string $domain, string $theme, array $plugins ): array {
			return array(
				'BlogID'     => $id,
				'SiteID'     => 1,
				'Domain'     => $domain,
				'Path'       => '/',
				'SiteURL'    => 'https://' . $domain,
				'HomeURL'    => 'https://' . $domain,
				'Plugins'    => $plugins,
				'Template'   => $theme,
				'Stylesheet' => $theme,
			);
		};
		$multisite = array(
			'Network'  => true,
			'Networks' => array(
				array(
					'SiteID' => 1,
					'Domain' => 'old.example',
					'Path'   => '/',
				),
			),
			'Sites'    => array( $site( 1, 'old.example', 'astra', array() ), $site( 2, 'shop.old.example', 'storefront', array( 'shop/shop.php' ) ), $site( 3, 'brand.example', 'astra', array() ) ),
			'Plugins'  => array( 'demo/demo.php' ),
		);
		$package   = json_decode( self::package( true, true ), true ); // Encrypted and compressed: multisite.json is too.
		$package   = array( 'SiteURL' => 'https://old.example', 'HomeURL' => 'https://old.example' ) + $package; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$builder   = new WpressBuilder( self::PASSWORD, 'gzip', true );
		$builder->add( 'package.json', (string) json_encode( $package ) );
		$builder->add( 'multisite.json', (string) json_encode( $multisite ) );
		$builder->add( 'uploads/sites/2/2026/09/shop.jpg', 'shop photo' );
		$builder->add( 'database.sql', $sql );
		$archive = $builder->save( $this->tmp . '/network.wpress' );

		$options                        = $this->options( $archive, true );
		$options['target']['home_url']  = 'https://staging.test';
		$options['target']['site_url']  = 'https://staging.test';
		$options['target']['uploads_url'] = 'https://staging.test/wp-content/uploads';
		$options['target']['multisite'] = true;
		$options['target']['network']   = array(
			'domain'    => 'staging.test',
			'path'      => '/',
			'subdomain' => true,
			'main_site' => 1,
			'networks'  => 1,
		);
		$job = $this->run_job( $options );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );

		$db    = $this->connect( self::TARGET );
		$value = static function ( string $table, string $name ) use ( $db ): string {
			return (string) ( $db->column( "SELECT option_value FROM {$table} WHERE option_name = " . $db->quote( $name ) )[0] ?? '' );
		};
		$this->assertSame( array( '1 staging.test /', '2 shop.staging.test /', '3 brand.example /' ), $db->column( "SELECT CONCAT(blog_id, ' ', domain, ' ', path) FROM shop_blogs ORDER BY blog_id" ) );
		$this->assertSame( array( 'staging.test /' ), $db->column( "SELECT CONCAT(domain, ' ', path) FROM shop_site" ) );
		$this->assertSame( 'https://shop.staging.test', $value( 'shop_2_options', 'home' ) );
		$this->assertSame( 'See https://shop.staging.test/deal and mail@staging.test', $value( 'shop_2_options', 'note' ) );
		$this->assertSame( 'https://brand.example', $value( 'shop_3_options', 'home' ) ); // Own domain, kept.
		$this->assertSame( 'storefront', $value( 'shop_2_options', 'template' ) );
		$this->assertSame( array( 'shop/shop.php' ), unserialize( $value( 'shop_2_options', 'active_plugins' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test.
		$this->assertSame( 'astra', $value( 'shop_options', 'template' ) );
		$this->assertSame( 'a:0:{}', $value( 'shop_2_options', 'shop_2_user_roles' ) );
		$this->assertSame( array( 'shop_capabilities', 'shop_2_capabilities' ), $db->column( 'SELECT meta_key FROM shop_usermeta ORDER BY umeta_id' ) );
		$sitewide = unserialize( (string) $db->column( "SELECT meta_value FROM shop_sitemeta WHERE meta_key = 'active_sitewide_plugins'" )[0] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test.
		$this->assertSame( array( 'demo/demo.php' ), array_keys( $sitewide ) );
		$this->assertSame( array( 'https://staging.test/' ), $db->column( "SELECT meta_value FROM shop_sitemeta WHERE meta_key = 'siteurl'" ) );
		$db->close();
		$this->assertSame( 'shop photo', file_get_contents( $this->tmp . '/target/wp-content/uploads/sites/2/2026/09/shop.jpg' ) );

		// On a single site, a network backup needs --site; sites picked one by one cannot go onto a network yet; both before anything changes.
		$job = $this->run_job( $this->options( $archive, true ) );
		$this->assertStringContainsString( 'choose the site to restore with --site', (string) $job->error );
		$multisite['Network'] = false;
		$builder              = new WpressBuilder( self::PASSWORD, 'gzip', true );
		$builder->add( 'package.json', (string) json_encode( $package ) );
		$builder->add( 'multisite.json', (string) json_encode( $multisite ) );
		$builder->add( 'database.sql', $sql );
		$options['archive'] = $builder->save( $this->tmp . '/picked.wpress' );
		$job                = $this->run_job( $options );
		$this->assertStringContainsString( 'picked one by one', (string) $job->error );
		$this->assertSame( 'https://shop.staging.test', $this->connect( self::TARGET )->column( "SELECT option_value FROM shop_2_options WHERE option_name = 'home'" )[0] );

		// One site of the whole network onto a single site.
		$single            = $this->options( $archive, true );
		$single['subsite'] = 'shop.old.example';
		$job               = $this->run_job( $single );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		$db = $this->connect( self::TARGET );
		$this->assertSame( 'https://new.example', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'home'" )[0] );
		$this->assertSame( 'See https://new.example/deal and mail@old.example', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'note'" )[0] ); // The network's e-mail domain stays.
		$this->assertSame( array( 'demo/demo.php', 'founders-migration-website/founders-migration-website.php', 'shop/shop.php' ), unserialize( (string) $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'active_plugins'" )[0] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test. FMW stays active.
		$this->assertSame( array( 'shop_user_roles' ), $db->column( "SELECT option_name FROM shop_options WHERE option_name LIKE '%user_roles'" ) );
		$this->assertSame( array( 'https://shop.staging.test' ), $db->column( "SELECT option_value FROM shop_2_options WHERE option_name = 'home'" ) ); // Left from the network restore, untouched.
		$db->close();
		$this->assertSame( 'shop photo', file_get_contents( $this->tmp . '/target/wp-content/uploads/2026/09/shop.jpg' ) );
	}

	/**
	 * A .wpress of sites picked one by one, as the Multisite Extension writes it: SERVMASK_PREFIX_mainsite_ for
	 * users and network tables, _basesite_ for the main site's, _<id>_ for the others, also in keys and option names.
	 *
	 * @param int[] $ids Sites in the backup (1 and/or 2).
	 * @return string Archive.
	 */
	private function picked_archive( array $ids ): string {
		$table = static function ( string $name, string $columns ): string {
			return "CREATE TABLE `SERVMASK_PREFIX_{$name}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
		};
		$opts  = '`option_id` bigint unsigned NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL, `option_value` longtext NOT NULL, `autoload` varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY (`option_id`), UNIQUE KEY `option_name` (`option_name`)';
		$posts = '`ID` bigint unsigned NOT NULL AUTO_INCREMENT, `post_author` bigint unsigned NOT NULL DEFAULT 0, `post_content` longtext NOT NULL, PRIMARY KEY (`ID`)';
		$sql   = $table( 'mainsite_users', '`ID` bigint unsigned NOT NULL AUTO_INCREMENT, `user_login` varchar(60) NOT NULL, `user_email` varchar(100) NOT NULL, PRIMARY KEY (`ID`)' )
			. $table( 'mainsite_usermeta', '`umeta_id` bigint unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint unsigned NOT NULL, `meta_key` varchar(255), `meta_value` longtext, PRIMARY KEY (`umeta_id`)' )
			. $table( 'mainsite_sitemeta', '`meta_id` bigint NOT NULL AUTO_INCREMENT, `site_id` bigint NOT NULL, `meta_key` varchar(255), `meta_value` longtext, PRIMARY KEY (`meta_id`)' )
			. $table( 'mainsite_blogs', '`blog_id` bigint NOT NULL AUTO_INCREMENT, `domain` varchar(200) NOT NULL, `path` varchar(100) NOT NULL, PRIMARY KEY (`blog_id`)' )
			. "INSERT INTO `SERVMASK_PREFIX_mainsite_users` VALUES (1,'admin','admin@neta.test'),(2,'shopeditor','se@shop.example'),(3,'mainonly','mo@main.example');\n"
			. "INSERT INTO `SERVMASK_PREFIX_mainsite_sitemeta` VALUES (1,1,'site_admins','a:1:{i:0;s:5:\"admin\";}');\n"
			. "INSERT INTO `SERVMASK_PREFIX_mainsite_blogs` VALUES (1,'neta.test','/'),(2,'shop.neta.test','/'),(3,'news.neta.test','/');\n"
			. "INSERT INTO `SERVMASK_PREFIX_mainsite_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES (1,'nickname','admin'),(1,'primary_blog','1'),(2,'nickname','se'),(3,'nickname','mo')";
		$sites = array(
			1 => array( 'basesite_', 'https://neta.test', 'twentytwentyfive', array() ),
			2 => array( '2_', 'https://shop.neta.test', 'storefront', array( 'shop/shop.php' ) ),
		);
		$meta  = array(
			1 => array( 1 => 'administrator', 3 => 'subscriber' ),
			2 => array( 1 => 'administrator', 2 => 'editor' ),
		);
		$json  = array();
		foreach ( $ids as $id ) {
			list( $mask, $url, $theme, $plugins ) = $sites[ $id ];
			foreach ( $meta[ $id ] as $user => $role ) {
				$sql .= ",({$user},'SERVMASK_PREFIX_{$mask}capabilities','a:1:{s:" . strlen( $role ) . ":\"{$role}\";b:1;}')";
			}
			$json[] = array(
				'BlogID'     => $id,
				'SiteID'     => 1,
				'Domain'     => (string) parse_url( $url, PHP_URL_HOST ), // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test.
				'Path'       => '/',
				'SiteURL'    => $url,
				'HomeURL'    => $url,
				'Plugins'    => $plugins,
				'Template'   => $theme,
				'Stylesheet' => $theme,
				'WordPress'  => array(
					'Uploads'    => '/srv/neta/wp-content/uploads' . ( 1 === $id ? '' : '/sites/' . $id ),
					'UploadsURL' => 'https://neta.test/wp-content/uploads/' . ( 1 === $id ? '' : 'sites/' . $id . '/' ),
				),
			);
		}
		$sql .= ";\n";
		foreach ( $ids as $id ) {
			list( $mask, $url ) = $sites[ $id ];
			$author             = 1 === $id ? 3 : 2;
			$sql               .= $table( $mask . 'options', $opts ) . $table( $mask . 'posts', $posts )
				. "INSERT INTO `SERVMASK_PREFIX_{$mask}options` (`option_name`, `option_value`) VALUES ('home','{$url}'),('siteurl','{$url}'),('SERVMASK_PREFIX_{$mask}user_roles','a:0:{}'),('active_plugins','a:0:{}'),('template',''),('stylesheet',''),('cache_dir','/srv/neta/wp-content/uploads" . ( 1 === $id ? '' : '/sites/2' ) . "/cache');\n"
				. "INSERT INTO `SERVMASK_PREFIX_{$mask}posts` VALUES (1,{$author},'<img src=\"https://neta.test/wp-content/uploads/" . ( 1 === $id ? '' : 'sites/2/' ) . "2026/09/p.jpg\"> {$url}/about, main https://neta.test/, shop https://shop.neta.test/, news https://neta.test/wp-content/uploads/sites/3/n.jpg');\n";
		}
		// A view of the network names its tables: it is left out, not recreated on the wrong tables.
		$sql .= "DROP VIEW IF EXISTS `SERVMASK_PREFIX_{$sites[ $ids[0] ][0]}published`;\nCREATE VIEW `SERVMASK_PREFIX_{$sites[ $ids[0] ][0]}published` AS select `ID` from `SERVMASK_PREFIX_{$sites[ $ids[0] ][0]}posts`;\n";
		$package = array(
			'SiteURL'   => 'https://neta.test',
			'HomeURL'   => 'https://neta.test',
			'Plugin'    => array( 'Version' => '7.111' ),
			'WordPress' => array(
				'Version' => '6.8',
				'Content' => '/srv/neta/wp-content', // As 7.85 writes it: no Absolute.
			),
			'Database'  => array( 'Prefix' => 'wp_' ),
		);
		$builder = new WpressBuilder( null, 'none', false );
		$builder->add( 'package.json', (string) json_encode( $package ) );
		$builder->add(
			'multisite.json',
			(string) json_encode(
				array(
					'Network'  => false,
					'Networks' => array(
						array(
							'SiteID' => 1,
							'Domain' => 'neta.test',
							'Path'   => '/',
						),
					),
					'Sites'    => $json,
					'Plugins'  => array( 'demo/demo.php' ),
					'Admins'   => array( 'admin' ),
				)
			)
		);
		foreach ( $ids as $id ) {
			$builder->add( 'uploads/' . ( 1 === $id ? '' : 'sites/2/' ) . '2026/09/p.jpg', 'photo of site ' . $id );
		}
		$builder->add( 'plugins/shop/shop.php', '<?php // shop' );
		$builder->add( 'database.sql', $sql );
		return $builder->save( $this->tmp . '/picked-' . implode( '-', $ids ) . '.wpress' );
	}

	public function test_one_picked_site_of_a_wpress_network_backup_restores_onto_a_single_site(): void {
		// The only site of the backup: no --site needed.
		$job = $this->run_job( $this->options( $this->picked_archive( array( 2 ) ), false ) );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		$db = $this->connect( self::TARGET );
		$this->assertSame( array( 'other_site_posts', 'shop_options', 'shop_posts', 'shop_usermeta', 'shop_users' ), $db->column( 'SHOW TABLES' ) );
		$this->assertSame( 'https://new.example', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'home'" )[0] );
		$this->assertSame( array( 'shop_user_roles' ), $db->column( "SELECT option_name FROM shop_options WHERE option_name LIKE '%user_roles'" ) );
		$this->assertSame( 'storefront', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'template'" )[0] );
		$this->assertSame( array( 'demo/demo.php', 'founders-migration-website/founders-migration-website.php', 'shop/shop.php' ), unserialize( (string) $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'active_plugins'" )[0] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test. FMW stays active.
		$this->assertSame( '<img src="https://new.example/wp-content/uploads/2026/09/p.jpg"> https://new.example/about, main https://neta.test/, shop https://new.example/, news https://neta.test/wp-content/uploads/sites/3/n.jpg', $db->column( 'SELECT post_content FROM shop_posts' )[0] ); // Site 3 was not picked: its media link stays.
		$this->assertSame( $this->tmp . '/target/wp-content/uploads/cache', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'cache_dir'" )[0] );
		$this->assertStringContainsString( 'Views and triggers of the network backup were left out', (string) file_get_contents( $this->tmp . '/jobs/' . $job->id . '/job.log' ) );
		// Admin (super admin) and the editor; mainonly has no role here. Keys lose the site ID.
		$this->assertSame( array( '1 admin', '2 shopeditor' ), $db->column( "SELECT CONCAT(ID, ' ', user_login) FROM shop_users ORDER BY ID" ) );
		$this->assertSame( array( '1 shop_capabilities a:1:{s:13:"administrator";b:1;}', '2 shop_capabilities a:1:{s:6:"editor";b:1;}' ), $db->column( "SELECT CONCAT(user_id, ' ', meta_key, ' ', meta_value) FROM shop_usermeta WHERE meta_key LIKE '%capabilities' ORDER BY user_id" ) );
		$this->assertSame( array(), $db->column( "SELECT meta_key FROM shop_usermeta WHERE meta_key LIKE 'SERVMASK%' OR meta_key = 'primary_blog'" ) );
		$db->close();
		$this->assertSame( 'photo of site 2', file_get_contents( $this->tmp . '/target/wp-content/uploads/2026/09/p.jpg' ) );
		$this->assertFileDoesNotExist( $this->tmp . '/target/wp-content/uploads/sites' );

		// Two picked sites: --site is needed; the main site then comes with its own users and media.
		$archive = $this->picked_archive( array( 1, 2 ) );
		$job     = $this->run_job( $this->options( $archive, false ) );
		$this->assertStringContainsString( 'Its sites: 1 neta.test/, 2 shop.neta.test/', (string) $job->error );
		$options            = $this->options( $archive, false );
		$options['subsite'] = '1';
		$job                = $this->run_job( $options );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		$db = $this->connect( self::TARGET );
		$this->assertSame( 'https://new.example', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'home'" )[0] );
		$this->assertSame( 'twentytwentyfive', $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'template'" )[0] );
		$this->assertSame( array( '1 admin', '3 mainonly' ), $db->column( "SELECT CONCAT(ID, ' ', user_login) FROM shop_users ORDER BY ID" ) );
		$this->assertSame( array( '1 a:1:{s:13:"administrator";b:1;}', '3 a:1:{s:10:"subscriber";b:1;}' ), $db->column( "SELECT CONCAT(user_id, ' ', meta_value) FROM shop_usermeta WHERE meta_key = 'shop_capabilities' ORDER BY user_id" ) );
		$this->assertSame( array( 'shop_user_roles' ), $db->column( "SELECT option_name FROM shop_options WHERE option_name LIKE '%user_roles'" ) );
		$this->assertStringContainsString( 'https://new.example/about', $db->column( 'SELECT post_content FROM shop_posts' )[0] );
		$this->assertStringContainsString( 'shop https://shop.neta.test/', $db->column( 'SELECT post_content FROM shop_posts' )[0] ); // The other site's link stays.
		$db->close();
		$this->assertSame( 'photo of site 1', file_get_contents( $this->tmp . '/target/wp-content/uploads/2026/09/p.jpg' ) );
		$this->assertFileDoesNotExist( $this->tmp . '/target/wp-content/uploads/sites' );
	}

	public function test_a_damaged_archive_is_refused_before_anything_changes(): void {
		$archive = $this->archive( true, true );
		$bytes   = (string) file_get_contents( $archive );
		$middle  = (int) ( strlen( $bytes ) / 2 );
		$bytes   = substr_replace( $bytes, chr( ord( $bytes[ $middle ] ) ^ 0x01 ), $middle, 1 );
		file_put_contents( $archive, $bytes );

		$job = $this->run_job( $this->options( $archive, true ) );
		$this->assertSame( Job::STATUS_FAILED, $job->status );
		$this->assertStringContainsString( 'CRC-32 mismatch', (string) $job->error );

		$db = $this->connect( self::TARGET );
		$this->assertSame( array( 'https://old-target.test' ), $db->column( "SELECT option_value FROM shop_options WHERE option_name = 'siteurl'" ) );
		$db->close();
		$this->assertSame( 'old theme', file_get_contents( $this->tmp . '/target/wp-content/themes/astra/style.css' ) );
	}

	public function test_a_wrong_key_is_refused_before_anything_changes(): void {
		$options               = $this->options( $this->archive( true, false ), true );
		$options['secret_wpress_key'] = Secrets::seal( WpressPackage::derive_key( 'wrong' ) );

		$job = $this->run_job( $options );
		$this->assertSame( Job::STATUS_FAILED, $job->status );
		$this->assertStringContainsString( 'wrong password or damaged archive', (string) $job->error );
		$this->assertSame( 'old theme', file_get_contents( $this->tmp . '/target/wp-content/themes/astra/style.css' ) );
	}

	public function test_hostile_sql_is_refused_before_any_file_is_written(): void {
		$builder = new WpressBuilder( null, 'none', true );
		$builder->add( 'package.json', self::package( false, false ) );
		$builder->add( 'themes/astra/style.css', 'evil theme' );
		$builder->add( 'database.sql', "CREATE TABLE `SERVMASK_PREFIX_x` (id int);\nINSERT INTO `SERVMASK_PREFIX_x` VALUES (LOAD_FILE('/etc/passwd'));\n" );
		$job = $this->run_job( $this->options( $builder->save( $this->tmp . '/evil.wpress' ), false ) );

		$this->assertSame( Job::STATUS_FAILED, $job->status );
		$this->assertSame( 'old theme', file_get_contents( $this->tmp . '/target/wp-content/themes/astra/style.css' ) );
	}
}
