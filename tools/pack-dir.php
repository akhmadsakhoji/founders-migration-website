<?php
/**
 * Founders Migration Website
 *
 * Packs a wp-content folder into format-v1 file parts with the real Scan and
 * Files steps, outside WordPress. Resumable: press Ctrl+C, then run again
 * with --resume=<job id>.
 *
 * Usage:
 *     php tools/pack-dir.php --src=/tmp/site/wp-content [--jobs=/tmp/fmw-jobs] [--part-size=1G] [--level=6]
 *     php tools/pack-dir.php --resume=<job id> [--jobs=/tmp/fmw-jobs]
 *
 * Exit codes: 0 done, 1 failed, 3 stopped (resumable).
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable -- Developer tool, not shipped with the plugin.

use Founders\Migration\Cli\ProgressBar;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\StepRegistry;
use Founders\Migration\Model\Export\FilesStep;
use Founders\Migration\Model\Export\ScanStep;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'FMWP_TESTS', true );
defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/fmw-no-wordpress/' ); // The library files refuse to load without it.
require dirname( __DIR__ ) . '/loader.php';

$options = getopt( '', array( 'src:', 'jobs:', 'part-size:', 'level:', 'resume:' ) );
if ( empty( $options['src'] ) && empty( $options['resume'] ) ) {
	fwrite( STDERR, "Usage: php tools/pack-dir.php --src=<wp-content> [--jobs=<dir>] [--part-size=1G] [--level=6]\n       php tools/pack-dir.php --resume=<job id> [--jobs=<dir>]\n" );
	exit( 1 );
}

$store    = new JobStore( $options['jobs'] ?? sys_get_temp_dir() . '/fmw-jobs' );
$registry = new StepRegistry();
$registry->register( 'files', array( ScanStep::class, FilesStep::class ) );

try {
	if ( ! empty( $options['resume'] ) ) {
		$job = $store->load( $options['resume'] );
	} else {
		$units = array( 'M' => 1 << 20, 'G' => 1 << 30 );
		$size  = $options['part-size'] ?? '1G';
		$src   = realpath( $options['src'] );
		if ( false === $src || ! is_dir( $src ) ) {
			fwrite( STDERR, "Source folder not found.\n" );
			exit( 1 );
		}
		$job = $store->create(
			'files',
			array(
				'content_dir'       => $src,
				'part_size'         => (int) ( (float) $size * ( $units[ strtoupper( substr( $size, -1 ) ) ] ?? 1 ) ),
				'compression_level' => (int) ( $options['level'] ?? 6 ),
				'skip_paths'        => array( 'fmw-backups', 'fmw-storage' ),
			)
		);
	}
} catch ( JobException $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

fwrite( STDERR, "Job {$job->id} in " . $store->dir( $job->id ) . "\n" );

$bar     = new ProgressBar();
$started = microtime( true );
$runner  = new Runner(
	$store,
	$registry,
	static function ( Job $job ) use ( $bar ) {
		if ( '' !== $job->phase ) {
			$bar->update( $job->phase, $job->bytes_done, $job->bytes_total );
		}
	}
);

try {
	$runner->run( $job, Deadline::unlimited(), true );
} catch ( JobException $e ) {
	$bar->finish();
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
$bar->finish();

if ( Job::STATUS_COMPLETED === $job->status ) {
	$raw      = array_sum( array_column( $job->data['parts'], 'bytes_raw' ) );
	$archived = array_sum( array_column( $job->data['parts'], 'bytes' ) );
	printf(
		"Packed %d files, %s -> %s in %d parts, %.1f s this run.\n",
		$job->data['scan']['files'],
		ProgressBar::bytes( (int) $raw ),
		ProgressBar::bytes( (int) $archived ),
		count( $job->data['parts'] ),
		microtime( true ) - $started
	);
	foreach ( $job->data['parts'] as $part ) {
		printf( "  %-24s %6d entries  %10s  sha256 %s…\n", $part['path'], $part['entries'], ProgressBar::bytes( $part['bytes'] ), substr( $part['sha256'], 0, 12 ) );
	}
	exit( 0 );
}

if ( Job::STATUS_FAILED === $job->status ) {
	fwrite( STDERR, "Failed: {$job->error}\nFix the cause, then: php tools/pack-dir.php --resume={$job->id}\n" );
	exit( 1 );
}

fwrite( STDERR, "Stopped. Continue with: php tools/pack-dir.php --resume={$job->id}" . ( isset( $options['jobs'] ) ? " --jobs={$options['jobs']}" : '' ) . "\n" );
exit( 3 );
