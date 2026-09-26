<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Remote;

use Founders\Migration\Job\Context;
use Founders\Migration\Job\Discardable;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;
use Founders\Migration\Remote\RemoteException;
use Founders\Migration\Remote\Storages;
use Founders\Migration\Storage\Backups;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions -- Resumable downloads need native file handles.

/**
 * Downloads a backup from cloud storage into the backups folder, resumably.
 *
 * Bytes go to <name>.partial in ranges sized from the measured speed; the
 * position is checkpointed, and a restart first cuts the file back to the
 * last checkpoint, so nothing is written twice. The finished file is
 * renamed into place; its parts are checked (SHA-256) when it is restored
 * or verified.
 *
 * Reads job options: remote (storage, key), archive_dir, archive_name.
 * Sets job data: archive (name, path, bytes).
 */
final class DownloadStep implements Step, Discardable {

	const CHUNK_MIN = 8388608;    // 8 MiB.
	const CHUNK_MAX = 536870912;  // 512 MiB.
	const TARGET    = 10.0;       // Seconds per request.

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Download';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the storage or object is gone, or the file cannot be written.
	 */
	public function run( Job $job, Context $context ): bool {
		$remote  = (array) ( $job->options['remote'] ?? array() );
		$storage = Storages::get( (string) ( $remote['storage'] ?? '' ) );
		if ( null === $storage ) {
			throw new JobException( 'The cloud storage of this job was deleted.' );
		}
		$name    = (string) ( $remote['name'] ?? basename( (string) ( $remote['key'] ?? '' ) ) );
		$final   = rtrim( (string) ( $job->options['archive_dir'] ?? '' ), '/' ) . '/' . basename( (string) ( $job->options['archive_name'] ?? '' ) );
		$partial = $final . '.partial';
		$state   = (array) ( $job->data['download'] ?? array() ) + array(
			'key'    => '',
			'size'   => -1,
			'etag'   => '',
			'offset' => 0,
			'speed'  => 0.0,
		);
		if ( '' === (string) $state['key'] && $state['size'] >= 0 ) {
			$state['key'] = (string) ( $remote['key'] ?? '' ); // Saved before the driver refactor: the key was an option.
		}

		try {
			$driver = Storages::driver( $storage );
			if ( $state['size'] < 0 ) {
				$object = $driver->find( $name );
				if ( null === $object ) {
					throw new JobException( sprintf( '%s is not in "%s" (any more).', $name, (string) $storage['name'] ) );
				}
				$state['key']          = $object['key'];
				$state['size']         = $object['size'];
				$state['etag']         = $object['etag'];
				$job->data['download'] = $state;
			}
			$key              = (string) $state['key'];
			$size             = (int) $state['size'];
			$job->bytes_total = $size;

			$handle = fopen( $partial, 'c+b' );
			if ( false === $handle ) {
				throw new JobException( sprintf( 'Cannot write %s.', $partial ) );
			}
			try {
				ftruncate( $handle, (int) $state['offset'] ); // Drop bytes written after the last checkpoint.
				fseek( $handle, (int) $state['offset'] );
				do {
					if ( (int) $state['offset'] >= $size ) {
						break;
					}
					$wanted = $state['speed'] > 0 ? (int) ( $state['speed'] * self::TARGET ) : self::CHUNK_MIN;
					$length = max( self::CHUNK_MIN, min( self::CHUNK_MAX, $wanted ) );
					if ( $state['speed'] > 0 ) {
						$length = min( $length, max( 1048576, (int) ( $state['speed'] * $context->remaining() * 0.8 ) ) ); // Fits the time slice.
					}
					if ( ! empty( $state['last'] ) ) {
						$length = min( $length, 4 * (int) $state['last'] ); // Grow step by step: one fast request says little about the link.
					}
					$length = min( $size - (int) $state['offset'], $length );
					$start  = microtime( true );
					$driver->download_range( $key, (int) $state['offset'], (int) $state['offset'] + $length - 1, $handle, (string) $state['etag'] );
					fflush( $handle );
					$seconds               = max( 0.001, microtime( true ) - $start );
					$state['offset']       = (int) $state['offset'] + $length;
					$state['last']         = $length;
					$state['speed']        = $state['speed'] > 0 ? 0.5 * $state['speed'] + 0.5 * $length / $seconds : $length / $seconds;
					$job->data['download'] = $state;
					$job->bytes_done       = (int) $state['offset'];
					$context->report_progress();
				} while ( $context->should_continue() );
			} finally {
				fclose( $handle );
			}
		} catch ( RemoteException $e ) {
			throw new JobException( sprintf( 'Download from "%s" failed: %s', (string) $storage['name'], $e->getMessage() ) );
		}

		if ( (int) $state['offset'] < $size ) {
			return false;
		}
		clearstatcache( true, $partial );
		if ( filesize( $partial ) !== $size ) {
			throw new JobException( sprintf( '%s has %d bytes instead of %d.', $partial, (int) filesize( $partial ), $size ) );
		}
		if ( file_exists( $final ) ) {
			$final = dirname( $final ) . '/' . Backups::unique_name( basename( $final ) ); // A file with that name appeared meanwhile.
		}
		if ( ! rename( $partial, $final ) ) {
			throw new JobException( sprintf( 'Cannot move the download to %s.', $final ) );
		}
		unset( $job->data['download'] );
		$job->data['archive'] = array(
			'name'  => basename( $final ),
			'path'  => $final,
			'bytes' => $size,
		);
		$context->log( sprintf( 'Downloaded %s (%d bytes) from "%s" to %s.', $name, $size, (string) $storage['name'], $final ) );
		return true;
	}

	/**
	 * Deletes the partial download of a cancelled job.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function discard( Job $job ): void {
		$name = basename( (string) ( $job->options['archive_name'] ?? '' ) );
		$dir  = rtrim( (string) ( $job->options['archive_dir'] ?? '' ), '/' );
		if ( '' !== $name && '' !== $dir && is_file( $dir . '/' . $name . '.partial' ) ) {
			unlink( $dir . '/' . $name . '.partial' );
		}
	}
}
