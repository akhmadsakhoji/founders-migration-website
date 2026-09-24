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
use Founders\Migration\Remote\S3Client;
use Founders\Migration\Remote\StorageOptions;
use Founders\Migration\Remote\Storages;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Uploads a backup to cloud storage, resumably.
 *
 * Small files go in one request; larger ones as a multipart upload whose
 * parts are sized from the measured speed (so each request stays well
 * inside a web time slice) and never exceed S3's 10,000 parts. The upload
 * ID and the ETags of finished parts are checkpointed with the job, so an
 * interrupted upload continues at the next part. The object's size is
 * checked at the end. A cancelled job aborts the multipart upload.
 *
 * Reads job options: remote (storage, delete_local), upload_path (upload jobs).
 * Reads job data: archive.path (backup jobs). Sets job data: remote.
 */
final class UploadStep implements Step, Discardable {

	const SINGLE_MAX = 8388608;    // 8 MiB: one PUT.
	const PART_MIN   = 8388608;    // 8 MiB, unless the time slice asks for less.
	const PART_MAX   = 536870912;  // 512 MiB.
	const TARGET     = 10.0;       // Seconds per part.

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Upload';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the storage is gone, the file is missing or the storage refuses.
	 */
	public function run( Job $job, Context $context ): bool {
		$remote = (array) ( $job->options['remote'] ?? array() );
		if ( empty( $remote['storage'] ) ) {
			return true;
		}
		$path = (string) ( $job->data['archive']['path'] ?? $job->options['upload_path'] ?? '' );
		if ( '' === $path || ! is_file( $path ) ) {
			throw new JobException( sprintf( 'The backup to upload is missing: %s', $path ) );
		}
		$storage = Storages::get( (string) $remote['storage'] );
		if ( null === $storage ) {
			throw new JobException( 'The cloud storage of this job was deleted. Add it again, or cancel the job (the backup stays on this server).' );
		}

		$size             = (int) filesize( $path );
		$key              = StorageOptions::key( $storage, basename( $path ) );
		$state            = (array) ( $job->data['upload'] ?? array() ) + array(
			'key'    => $key,
			'id'     => '',
			'parts'  => array(),
			'offset' => 0,
			'speed'  => 0.0,
		);
		$job->bytes_total = $size;

		try {
			$client = Storages::client( $storage );
			if ( '' !== $state['id'] && $state['key'] !== $key ) {
				// The storage folder was changed during the upload: drop the old upload and start again.
				$client->abort_multipart( (string) $state['key'], (string) $state['id'] );
				$state = array(
					'key'    => $key,
					'id'     => '',
					'parts'  => array(),
					'offset' => 0,
					'speed'  => $state['speed'],
				);
			}
			if ( $size <= self::SINGLE_MAX ) {
				$client->put_file( $key, $path, 0, $size, Storages::upload_headers( $storage ) );
			} else {
				if ( '' === $state['id'] ) {
					$state['id']         = $client->create_multipart( $key, Storages::upload_headers( $storage ) );
					$job->data['upload'] = $state;
					return false; // Checkpoint the upload ID now, so a crash cannot leave an unknown upload behind.
				}
				$first = true;
				do {
					$left = $size - (int) $state['offset'];
					if ( $left <= 0 ) {
						break;
					}
					$number = count( $state['parts'] ) + 1;
					$length = min( $left, self::part_size( $left, $number, (float) $state['speed'] ) );
					if ( ! empty( $state['last'] ) ) {
						$length = min( $length, max( 4 * (int) $state['last'], self::needed( $left, $number ) ) ); // Grow step by step.
					}
					if ( $state['speed'] > 0 ) {
						// Keep each request inside the time slice, so progress is saved before the host may end the request.
						$fits = self::fit( (float) $state['speed'] * $context->remaining() * 0.8, $left, $number );
						if ( ! $first && $length > $fits ) {
							break;
						}
						$length = min( $length, max( $fits, self::needed( $left, $number ) ) );
					}
					$first = false;
					$start = microtime( true );
					try {
						$etag = $client->upload_part( $key, (string) $state['id'], $number, $path, (int) $state['offset'], $length );
					} catch ( RemoteException $e ) {
						if ( 'NoSuchUpload' !== $e->error_code ) {
							throw new JobException( sprintf( 'Upload to "%s" failed: %s', (string) $storage['name'], $e->getMessage() ) );
						}
						// The storage dropped the unfinished upload (expired): start it again.
						$context->log( 'The storage no longer knows the unfinished upload; starting it again.' );
						$state               = array(
							'key'    => $key,
							'id'     => $client->create_multipart( $key, Storages::upload_headers( $storage ) ),
							'parts'  => array(),
							'offset' => 0,
							'speed'  => $state['speed'],
						);
						$job->data['upload'] = $state;
						return false;
					}
					$seconds                            = max( 0.001, microtime( true ) - $start );
					$state['parts'][ (string) $number ] = $etag;
					$state['offset']                    = (int) $state['offset'] + $length;
					$state['last']                      = $length;
					$state['speed']                     = $state['speed'] > 0 ? 0.5 * $state['speed'] + 0.5 * $length / $seconds : $length / $seconds;
					$job->data['upload']                = $state;
					$job->bytes_done                    = (int) $state['offset'];
					$context->report_progress();
				} while ( $context->should_continue() );

				if ( (int) $state['offset'] < $size ) {
					return false;
				}
				$parts = array();
				foreach ( $state['parts'] as $number => $etag ) {
					$parts[ (int) $number ] = (string) $etag;
				}
				try {
					$client->complete_multipart( $key, (string) $state['id'], $parts );
				} catch ( RemoteException $e ) {
					// Completed already (a retried request, or a crash right after it): fine when the object is whole.
					$done = 'NoSuchUpload' === $e->error_code ? $client->head( $key ) : null;
					if ( null === $done || $done['size'] !== $size ) {
						throw new JobException( sprintf( 'Upload to "%s" failed: %s', (string) $storage['name'], $e->getMessage() ) );
					}
				}
			}

			$object = $client->head( $key );
		} catch ( RemoteException $e ) {
			throw new JobException( sprintf( 'Upload to "%s" failed: %s', (string) $storage['name'], $e->getMessage() ) );
		}
		if ( null === $object || $object['size'] !== $size ) {
			throw new JobException( sprintf( 'The uploaded copy in "%s" has %s bytes instead of %d.', (string) $storage['name'], null === $object ? 'no' : (string) $object['size'], $size ) );
		}

		unset( $job->data['upload'] );
		$job->data['remote'] = array(
			'storage' => (string) $storage['id'],
			'name'    => (string) $storage['name'],
			'key'     => $key,
			'size'    => $size,
		);
		$job->bytes_done     = $size;
		$context->log( sprintf( 'Uploaded %s (%d bytes) to "%s" as %s.', basename( $path ), $size, (string) $storage['name'], $key ) );

		if ( ! empty( $remote['delete_local'] ) && 'backup' === $job->type ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plain PHP in jobs.
			$job->data['archive']['deleted_local'] = true;
			$context->log( 'Deleted the copy on this server, as asked.' );
		}
		return true;
	}

	/**
	 * Largest part that fits $bytes: whole MiB, at least 5 MiB (S3's minimum for all but the last part).
	 *
	 * @param float $bytes  Bytes that fit in the time left.
	 * @param int   $left   Bytes left.
	 * @param int   $number Part number.
	 * @return int
	 */
	private static function fit( float $bytes, int $left, int $number ): int {
		$fits = max( S3Client::MIN_PART, (int) ( floor( $bytes / 1048576 ) * 1048576 ) );
		return min( $left, max( $fits, self::needed( $left, $number ) ) );
	}

	/**
	 * Smallest part that still keeps the upload within 10,000 parts.
	 *
	 * @param int $left   Bytes left.
	 * @param int $number Part number.
	 * @return int
	 */
	private static function needed( int $left, int $number ): int {
		return (int) ( ceil( $left / max( 1, S3Client::MAX_PARTS - $number + 1 ) / 1048576 ) * 1048576 );
	}

	/**
	 * Aborts an unfinished multipart upload of a cancelled job.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function discard( Job $job ): void {
		$state   = (array) ( $job->data['upload'] ?? array() );
		$storage = Storages::get( (string) ( $job->options['remote']['storage'] ?? '' ) );
		if ( '' === (string) ( $state['id'] ?? '' ) || null === $storage ) {
			return;
		}
		try {
			Storages::client( $storage )->abort_multipart( (string) $state['key'], (string) $state['id'] );
		} catch ( RemoteException $e ) {
			unset( $e ); // Best effort: storages also expire unfinished uploads by lifecycle rules.
		}
	}

	/**
	 * Size of the next part: about TARGET seconds at the measured speed,
	 * within 8 MiB..512 MiB, and large enough to stay under 10,000 parts.
	 *
	 * @param int   $left   Bytes left.
	 * @param int   $number Number of this part.
	 * @param float $speed  Measured bytes per second (0 before the first part).
	 * @return int
	 */
	public static function part_size( int $left, int $number, float $speed ): int {
		$wanted = $speed > 0 ? (int) ( $speed * self::TARGET ) : self::PART_MIN;
		$wanted = max( self::PART_MIN, min( self::PART_MAX, $wanted ) );
		$size   = max( $wanted, self::needed( $left, $number ) );
		return (int) ( ceil( $size / 1048576 ) * 1048576 ); // Whole MiB.
	}
}
