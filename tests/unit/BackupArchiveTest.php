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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Archive\PlainFileSink;
use Founders\Migration\Archive\TarWriter;
use Founders\Migration\Database\Connection;
use Founders\Migration\Database\DatabaseException;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\StepRegistry;
use Founders\Migration\Model\Export\DatabaseStep;
use Founders\Migration\Model\Export\FilesStep;
use Founders\Migration\Model\Export\PackageStep;
use Founders\Migration\Model\Export\ScanStep;
use Founders\Migration\Tests\TestCase;

/**
 * Full backup job (scan, database, files, package) and the resulting .fmw file.
 *
 * The database part runs when a MySQL/MariaDB server is reachable (see
 * DatabaseDumpTest for the settings); otherwise the backup excludes it.
 */
final class BackupArchiveTest extends TestCase {

	const SOURCE  = 'fmwp_backup_test';
	const RESTORE = 'fmwp_backup_restore';

	/**
	 * Job store.
	 *
	 * @var JobStore
	 */
	private $store;

	/**
	 * Registry with the backup job type.
	 *
	 * @var StepRegistry
	 */
	private $registry;

	/**
	 * Server settings, or null without a server.
	 *
	 * @var array{host:string,user:string,pass:string}|null
	 */
	private $server = null;

	protected function setUp(): void {
		parent::setUp();
		$this->store    = new JobStore( $this->tmp . '/wp-content/fmw-storage/jobs' );
		$this->registry = new StepRegistry();
		$this->registry->register( 'backup', array( ScanStep::class, DatabaseStep::class, FilesStep::class, PackageStep::class ) );

		$files = array(
			'index.php'                    => '<?php // Silence is golden.',
			'plugins/shop/shop.php'        => str_repeat( "<?php // shop\n", 3000 ),
			'themes/astra/style.css'       => str_repeat( "a{color:red}\n", 5000 ),
			'uploads/2026/09/photo.jpg'    => random_bytes( 180000 ),
			'uploads/2026/09/clip.mp4'     => random_bytes( 250000 ),
			'uploads/2026/09/doc-ñ.pdf'    => random_bytes( 40000 ),
			'fmw-backups/older.fmw'        => 'old backup',
		);
		foreach ( $files as $path => $contents ) {
			$this->make_file( 'wp-content/' . $path, $contents );
		}

		$this->server = array(
			'host' => (string) ( getenv( 'FMWP_TEST_DB_HOST' ) ?: 'localhost' ),
			'user' => (string) ( getenv( 'FMWP_TEST_DB_USER' ) ?: 'root' ),
			'pass' => (string) ( getenv( 'FMWP_TEST_DB_PASSWORD' ) ?: '' ),
		);
		try {
			$admin = $this->connect( '' );
			foreach ( array( self::SOURCE, self::RESTORE ) as $name ) {
				$admin->query( 'DROP DATABASE IF EXISTS ' . $name );
				$admin->query( 'CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4' );
			}
			$admin->close();
			$db = $this->connect( self::SOURCE );
			$db->query( 'CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL)' );
			$db->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'https://example.com'), ('blogname', 'Toko Contoh ñ')" );
			$db->query( 'CREATE TABLE wp_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title text, post_content longtext)' );
			for ( $i = 1; $i <= 300; $i++ ) {
				$db->query( "INSERT INTO wp_posts (post_title, post_content) VALUES ('Post {$i}', REPEAT('Isi artikel {$i}. ', {$i}))" );
			}
			$db->close();
			Connection::set_factory(
				function () {
					return $this->connect( self::SOURCE );
				}
			);
		} catch ( DatabaseException $e ) {
			$this->server = null;
		}
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
	 * Backup job options, as BackupOptions would build them.
	 *
	 * @return array<string,mixed>
	 */
	private function options(): array {
		return array(
			'content_dir'      => $this->tmp . '/wp-content',
			'skip_paths'       => array( 'fmw-backups', 'fmw-storage' ),
			'exclude_database' => null === $this->server,
			'table_prefix'     => 'wp_',
			'part_size'        => 200000,
			'batch_rows'       => 40,
			'sql_chunk_bytes'  => 30000,
			'archive_dir'      => $this->tmp . '/wp-content/fmw-backups',
			'archive_name'     => 'example.com-20260924-180000-a1b2c3.fmw',
			'generator'        => 'fmw/test',
			'site'             => array(
				'home_url'     => 'https://example.com',
				'site_url'     => 'https://example.com',
				'table_prefix' => 'wp_',
				'multisite'    => false,
			),
		);
	}

	/**
	 * Runs a backup to the end, one fresh "process" per slice.
	 *
	 * @param callable|null $between Called between slices.
	 * @return Job
	 */
	private function backup( ?callable $between = null ): Job {
		$job = $this->store->create( 'backup', $this->options() );
		for ( $i = 0; $i < 20000; $i++ ) {
			$job = ( new Runner( $this->store, $this->registry, null, 0.001 ) )->run( $this->store->load( $job->id ), new Deadline( 0.0 ) );
			if ( Job::STATUS_RUNNING !== $job->status ) {
				$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
				return $job;
			}
			if ( null !== $between ) {
				$between( $job );
			}
		}
		$this->fail( 'Backup did not finish.' );
	}

	public function test_archive_layout_and_manual_restore_follow_the_spec(): void {
		$partial = $this->tmp . '/wp-content/fmw-backups/example.com-20260924-180000-a1b2c3.fmw.partial';
		$job     = $this->backup(
			static function () use ( $partial ) {
				if ( is_file( $partial ) ) {
					file_put_contents( $partial, random_bytes( 200 ), FILE_APPEND ); // Torn write.
				}
			}
		);
		$archive = $job->data['archive']['path'];

		$this->assertFileExists( $archive );
		$this->assertFileDoesNotExist( $partial );
		$this->assertSame( array(), ( new FmwArchive( $archive ) )->verify() );

		// Section 1: fmw.json first, database parts, file parts, manifest.json last.
		list( $code, $listing ) = $this->run_command( 'tar -tf ' . escapeshellarg( $archive ) );
		$this->assertSame( 0, $code, $listing );
		$names = explode( "\n", trim( $listing, "\n" ) );
		$this->assertSame( 'fmw.json', $names[0] );
		$this->assertSame( 'manifest.json', end( $names ) );
		$kinds = array_map(
			static function ( $name ) {
				return strtok( $name, '/' );
			},
			array_slice( $names, 1, -1 )
		);
		$this->assertSame( $kinds, array_merge( array_filter( $kinds, static function ( $k ) { return 'database' === $k; } ), array_filter( $kinds, static function ( $k ) { return 'files' === $k; } ) ) );

		$archive_reader = new FmwArchive( $archive );
		$this->assertSame( 1, $archive_reader->header()['version'] );
		$manifest = $archive_reader->manifest();
		$this->assertSame( 'https://example.com', $manifest['site']['home_url'] );
		$this->assertSame( count( $names ) - 2, count( $manifest['parts'] ) );
		$this->assertSame( array_sum( array_column( $manifest['parts'], 'bytes' ) ), $manifest['totals']['bytes_archived'] );

		// Section 8: restore by hand with tar, gunzip and mysql.
		$work = $this->tmp . '/manual';
		$site = $this->tmp . '/restored-wp-content';
		mkdir( $work );
		mkdir( $site );
		list( $code, $output ) = $this->run_command( 'tar -xf ' . escapeshellarg( $archive ) . ' -C ' . escapeshellarg( $work ) );
		$this->assertSame( 0, $code, $output );
		foreach ( glob( $work . '/files/part-*' ) as $part ) {
			list( $code, $output ) = $this->run_command( 'tar -xf ' . escapeshellarg( $part ) . ' -C ' . escapeshellarg( $site ) );
			$this->assertSame( 0, $code, $output );
		}
		foreach ( array( 'index.php', 'plugins/shop/shop.php', 'themes/astra/style.css', 'uploads/2026/09/photo.jpg', 'uploads/2026/09/clip.mp4', 'uploads/2026/09/doc-ñ.pdf' ) as $file ) {
			$this->assertSame( sha1_file( $this->tmp . '/wp-content/' . $file ), sha1_file( $site . '/' . $file ), $file );
		}
		$this->assertFileDoesNotExist( $site . '/fmw-backups/older.fmw' );

		if ( null !== $this->server ) {
			$client = $this->tool( 'mysql' ) ?? $this->tool( 'mariadb' );
			list( $host, $port, $socket ) = Connection::parse_host( $this->server['host'] );
			$args = '--user=' . escapeshellarg( $this->server['user'] ) . ' --password=' . escapeshellarg( $this->server['pass'] ) . ( $socket ? ' --socket=' . escapeshellarg( $socket ) : ' --host=' . escapeshellarg( $host ) ) . ( $port ? ' --port=' . $port : '' );
			$sql  = glob( $work . '/database/*.sql.gz' );
			sort( $sql );
			$this->assertNotSame( array(), $sql );
			foreach ( $sql as $file ) {
				list( $code, $output ) = $this->run_command( 'gunzip -c ' . escapeshellarg( $file ) . " | {$client} {$args} " . self::RESTORE );
				$this->assertSame( 0, $code, $output );
			}
			$db = $this->connect( '' );
			foreach ( array( 'wp_options', 'wp_posts' ) as $table ) {
				$source   = $db->rows( 'CHECKSUM TABLE ' . self::SOURCE . '.' . $table )[0]['Checksum'];
				$restored = $db->rows( 'CHECKSUM TABLE ' . self::RESTORE . '.' . $table )[0]['Checksum'];
				$this->assertSame( $source, $restored, $table );
			}
			$db->close();
		}
	}

	public function test_packed_parts_are_deleted_along_the_way(): void {
		$job   = $this->backup();
		$left  = glob( $this->store->dir( $job->id ) . '/{files,database}/*', GLOB_BRACE );
		$total = count( ( new FmwArchive( $job->data['archive']['path'] ) )->manifest()['parts'] );

		$this->assertGreaterThan( 3, $total );
		$this->assertLessThan( $total, count( $left ), 'parts packed in earlier slices were removed' );
	}

	public function test_corruption_and_truncation_are_reported(): void {
		$archive = $this->backup()->data['archive']['path'];
		$size    = filesize( $archive );

		// Flip one byte inside the data of the largest part (block numbers from GNU tar -R).
		list( , $blocks ) = $this->run_command( 'tar -tRf ' . escapeshellarg( $archive ) );
		preg_match_all( '/^block (\d+): (\S+)$/m', $blocks, $m, PREG_SET_ORDER );
		$parts  = ( new FmwArchive( $archive ) )->manifest()['parts'];
		$sizes  = array_column( $parts, 'bytes', 'path' );
		$target = array_search( max( $sizes ), $sizes, true );
		$offset = null;
		foreach ( $m as $match ) {
			if ( $match[2] === $target ) {
				$offset = ( (int) $match[1] + 1 ) * 512 + 1000;
			}
		}
		$this->assertNotNull( $offset );

		$flipped = $this->tmp . '/flipped.fmw';
		copy( $archive, $flipped );
		$handle = fopen( $flipped, 'r+b' );
		fseek( $handle, $offset );
		$byte = (string) fread( $handle, 1 );
		fseek( $handle, $offset );
		fwrite( $handle, chr( ord( $byte ) ^ 0xff ) );
		fclose( $handle );
		$problems = ( new FmwArchive( $flipped ) )->verify();
		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'SHA-256 mismatch', $problems[0] );

		$truncated = $this->tmp . '/truncated.fmw';
		file_put_contents( $truncated, (string) file_get_contents( $archive, false, null, 0, (int) ( $size / 2 ) ) );
		$problems = ( new FmwArchive( $truncated ) )->verify();
		$this->assertNotSame( array(), $problems );
		$this->assertStringContainsString( 'no manifest.json', $problems[0] );
	}

	public function test_newer_format_versions_and_other_files_are_refused(): void {
		$newer  = $this->tmp . '/newer.fmw';
		$writer = new TarWriter( new PlainFileSink( $newer ) );
		$writer->add_string( 'fmw.json', '{"format":"fmw","version":2}' );
		$writer->finish();

		try {
			( new FmwArchive( $newer ) )->header();
			$this->fail( 'Expected ArchiveException.' );
		} catch ( ArchiveException $e ) {
			$this->assertStringContainsString( 'newer than this plugin supports', $e->getMessage() );
		}

		$other = $this->make_file( 'not-a-backup.fmw', str_repeat( 'x', 2048 ) );
		$this->expectException( ArchiveException::class );
		( new FmwArchive( $other ) )->header();
	}
}
