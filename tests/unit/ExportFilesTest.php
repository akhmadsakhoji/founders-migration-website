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

use Founders\Migration\Archive\Extractor;
use Founders\Migration\Archive\TarReader;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\StepRegistry;
use Founders\Migration\Model\Export\FilesStep;
use Founders\Migration\Model\Export\Filter;
use Founders\Migration\Model\Export\ScanStep;
use Founders\Migration\Tests\TestCase;

/**
 * Scan + Files steps end to end: wp-content in, format-v1 parts out.
 */
final class ExportFilesTest extends TestCase {

	/**
	 * Fake wp-content.
	 *
	 * @var string
	 */
	private $content;

	/**
	 * Job store.
	 *
	 * @var JobStore
	 */
	private $store;

	/**
	 * Registry with a "files" job type.
	 *
	 * @var StepRegistry
	 */
	private $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->content  = $this->tmp . '/wp-content';
		$this->store    = new JobStore( $this->tmp . '/jobs' );
		$this->registry = new StepRegistry();
		$this->registry->register( 'files', array( ScanStep::class, FilesStep::class ) );

		$files = array(
			'index.php'                                   => "<?php\n// Silence is golden.\n",
			'plugins/akismet/akismet.php'                  => str_repeat( "<?php // plugin\n", 2000 ),
			'plugins/old-plugin/old.php'                   => str_repeat( 'x', 3000 ),
			'plugins/hello.php'                            => '<?php // Hello Dolly',
			'themes/astra/style.css'                       => str_repeat( "body{margin:0}\n", 3000 ),
			'themes/twentytwenty/style.css'                => str_repeat( 'y', 4000 ),
			'mu-plugins/loader.php'                        => '<?php // mu',
			'uploads/2026/09/photo.jpg'                    => random_bytes( 150000 ),
			'uploads/2026/09/video.mp4'                    => random_bytes( 300000 ),
			'uploads/2026/09/foto-ñ-日本.jpg'             => random_bytes( 5000 ),
			'uploads/' . str_repeat( 'deep/', 25 ) . 'x.txt' => 'deep',
			'uploads/empty.txt'                            => '',
			'cache/page.html'                              => 'cached',
			'upgrade/tmp.zip'                              => 'temp',
			'fmw-backups/old.fmw'                          => 'previous backup',
			'fmw-storage/jobs/x/state.json'                => '{}',
		);
		foreach ( $files as $path => $contents ) {
			$this->make_file( 'wp-content/' . $path, $contents );
		}
		mkdir( $this->content . '/uploads/empty-folder', 0755, true );
		symlink( '2026/09/photo.jpg', $this->content . '/uploads/latest.jpg' );
	}

	/**
	 * Default job options.
	 *
	 * @param array<string,mixed> $extra Overrides.
	 * @return array<string,mixed>
	 */
	private function options( array $extra = array() ): array {
		return $extra + array(
			'content_dir' => $this->content,
			'skip_paths'  => array( 'fmw-backups', 'fmw-storage' ),
			'part_size'   => 200000, // Small parts to force rotation.
		);
	}

	/**
	 * Runs a job to the end, reloading it from disk before every call like a new web request.
	 *
	 * @param Job      $job      Job.
	 * @param Deadline $deadline Budget per call.
	 * @param callable $between  Called between calls with the job.
	 * @return Job
	 */
	private function run_to_end( Job $job, ?Deadline $deadline = null, ?callable $between = null ): Job {
		for ( $i = 0; $i < 10000; $i++ ) {
			$runner = new Runner( $this->store, $this->registry, null, 0.001 );
			$job    = $runner->run( $this->store->load( $job->id ), $deadline ?? Deadline::unlimited() );
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
	 * Extracts every part of a job into a folder.
	 *
	 * @param Job $job Job.
	 * @return string Folder.
	 */
	private function extract_parts( Job $job ): string {
		$out = $this->tmp . '/restored-' . $job->id;
		foreach ( $job->data['parts'] as $part ) {
			$path = $this->store->dir( $job->id ) . '/' . $part['path'];
			( new Extractor( $out ) )->extract_all( TarReader::open( $path ) );
		}
		return $out;
	}

	/**
	 * Relative file paths (and symlinks) under a folder, with contents.
	 *
	 * @param string $root Folder.
	 * @return array<string,string>
	 */
	private function tree( string $root ): array {
		$out      = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $path => $info ) {
			$relative = substr( $path, strlen( $root ) + 1 );
			if ( $info->isLink() ) {
				$out[ $relative ] = 'link:' . readlink( $path );
			} elseif ( $info->isDir() ) {
				$out[ $relative . '/' ] = 'dir';
			} else {
				$out[ $relative ] = sha1_file( $path );
			}
		}
		ksort( $out );
		return $out;
	}

	public function test_packs_everything_except_fmw_and_scratch_folders(): void {
		$job = $this->run_to_end( $this->store->create( 'files', $this->options() ) );

		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		$expected = $this->tree( $this->content );
		foreach ( array_keys( $expected ) as $path ) {
			if ( preg_match( '#^(fmw-backups|fmw-storage|upgrade)(/|$)#', $path ) ) {
				unset( $expected[ $path ] );
			}
		}
		$this->assertSame( $expected, $this->tree( $this->extract_parts( $job ) ) );
		$this->assertSame( 0, $job->data['files']['skipped'] );
	}

	public function test_parts_follow_the_format_rules(): void {
		$job = $this->run_to_end( $this->store->create( 'files', $this->options() ) );
		$dir = $this->store->dir( $job->id );

		$this->assertGreaterThan( 2, count( $job->data['parts'] ), 'small part size forces rotation' );
		$entries = 0;
		foreach ( $job->data['parts'] as $i => $part ) {
			$this->assertSame( sprintf( 'files/part-%04d.%s', $this->part_number( $part['path'] ), 'gzip' === $part['compression'] ? 'tar.gz' : 'tar' ), $part['path'] );
			$this->assertSame( hash_file( 'sha256', $dir . '/' . $part['path'] ), $part['sha256'] );
			$this->assertSame( filesize( $dir . '/' . $part['path'] ), $part['bytes'] );

			$reader = TarReader::open( $dir . '/' . $part['path'] );
			$count  = 0;
			while ( null !== ( $entry = $reader->next() ) ) {
				++$count;
				if ( $entry->is_file() ) {
					$this->assertSame( ! ( new Filter( array() ) )->is_stored( $entry->name ), 'gzip' === $part['compression'], $entry->name . ' in the right kind of part' );
				}
			}
			$this->assertSame( $part['entries'], $count, $part['path'] );
			$entries += $count;
		}

		$scan = $job->data['scan'];
		$this->assertSame( $scan['files'] + $scan['dirs'] + $scan['links'], $entries );
		$this->assertSame( $scan['bytes'], $job->bytes_done );
	}

	public function test_resumes_after_every_slice_and_torn_writes(): void {
		// Every call does ~1 ms of work; between calls, garbage is appended to the open
		// parts, as if the previous process died in the middle of a write.
		$tear = function ( Job $job ) {
			foreach ( (array) ( $job->cursor['open'] ?? array() ) as $kind => $part ) {
				$path = sprintf( '%s/files/part-%04d.%s', $this->store->dir( $job->id ), $part['n'], 'gz' === $kind ? 'tar.gz' : 'tar' );
				file_put_contents( $path, random_bytes( 100 ), FILE_APPEND );
			}
		};
		$job = $this->run_to_end( $this->store->create( 'files', $this->options( array( 'part_size' => 100000 ) ) ), new Deadline( 0.0 ), $tear );

		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		$reference = $this->run_to_end( $this->store->create( 'files', $this->options( array( 'part_size' => 100000 ) ) ) );
		$this->assertSame( $this->tree( $this->extract_parts( $reference ) ), $this->tree( $this->extract_parts( $job ) ) );
	}

	public function test_large_file_is_written_across_several_slices(): void {
		$this->make_file( 'wp-content/uploads/big.bin', random_bytes( 3 * 1048576 + 17 ) );
		// 1 MiB slices exercise mid-file resume without a multi-gigabyte fixture.
		$job   = $this->store->create(
			'files',
			$this->options(
				array(
					'part_size'   => 1 << 30,
					'slice_bytes' => 1048576,
				)
			)
		);
		$calls = 0;
		$job   = $this->run_to_end(
			$job,
			new Deadline( 0.0 ),
			static function () use ( &$calls ) {
				++$calls;
			}
		);

		$this->assertSame( Job::STATUS_COMPLETED, $job->status, (string) $job->error );
		$restored = $this->extract_parts( $job );
		$this->assertSame( sha1_file( $this->content . '/uploads/big.bin' ), sha1_file( $restored . '/uploads/big.bin' ) );
		$this->assertGreaterThan( 3, $calls );
	}

	public function test_exclusions_match_ai1wm_flags(): void {
		$job = $this->run_to_end(
			$this->store->create(
				'files',
				$this->options(
					array(
						'exclude'        => array( 'cache', 'media', 'inactive-plugins', 'inactive-themes', 'muplugins' ),
						'active_plugins' => array( 'akismet', 'hello.php' ),
						'active_themes'  => array( 'astra' ),
						'exclude_paths'  => array( 'plugins/akismet/*.log' ),
					)
				)
			)
		);

		$tree = array_keys( $this->tree( $this->extract_parts( $job ) ) );
		$this->assertSame(
			array(
				'index.php',
				'plugins/',
				'plugins/akismet/',
				'plugins/akismet/akismet.php',
				'plugins/hello.php',
				'themes/',
				'themes/astra/',
				'themes/astra/style.css',
			),
			$tree
		);
	}

	public function test_file_deleted_after_the_scan_is_skipped_and_logged(): void {
		$job     = $this->store->create( 'files', $this->options() );
		$deleted = false;
		$job     = $this->run_to_end(
			$job,
			new Deadline( 0.0 ),
			function ( Job $job ) use ( &$deleted ) {
				if ( ! $deleted && 1 === $job->step ) {
					unlink( $this->content . '/themes/astra/style.css' );
					$deleted = true;
				}
			}
		);

		$this->assertTrue( $deleted );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status );
		$this->assertSame( 1, $job->data['files']['skipped'] );
		$this->assertContains( 'Skipped themes/astra/style.css: missing or unreadable.', array_map( static function ( $line ) {
			return substr( $line, 21 );
		}, $this->store->log_lines( $job->id, 0 ) ) );
	}

	public function test_file_that_shrank_keeps_the_part_valid(): void {
		$job    = $this->store->create( 'files', $this->options() );
		$shrunk = false;
		$job    = $this->run_to_end(
			$job,
			new Deadline( 0.0 ),
			function ( Job $job ) use ( &$shrunk ) {
				if ( ! $shrunk && 1 === $job->step ) {
					file_put_contents( $this->content . '/themes/astra/style.css', 'short' );
					$shrunk = true;
				}
			}
		);

		$this->assertTrue( $shrunk );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status );
		$this->assertSame( 1, $job->data['files']['changed'] );
		$restored = $this->extract_parts( $job );
		$this->assertSame( 'short', rtrim( (string) file_get_contents( $restored . '/themes/astra/style.css' ), "\0" ) );
	}

	/**
	 * Part number from a part path.
	 *
	 * @param string $path Part path.
	 * @return int
	 */
	private function part_number( string $path ): int {
		return (int) substr( basename( $path ), 5, 4 );
	}
}
