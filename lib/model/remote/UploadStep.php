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
use Founders\Migration\Remote\StorageOptions;
use Founders\Migration\Remote\Storages;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads a backup to cloud storage, resumably, through the storage's driver.
 *
 * Chunks are sized from the measured speed, so each request stays well
 * inside a web time slice; the driver rounds them to its own rules (S3
 * parts of at least 5 MiB and at most 10,000 of them, Google Drive chunks
 * of 256 KiB multiples). The driver's upload state is checkpointed with the
 * job, and synchronised with the storage at the start of every slice, so an
 * interrupted upload continues where the storage says it stopped. The size
 * is checked at the end, and a cancelled job aborts the upload.
 *
 * Reads job options: remote (storage, delete_local), upload_path (upload jobs).
 * Reads job data: archive.path (backup jobs). Sets job data: remote.
 */
final class UploadStep implements Step, Discardable {

	const PART_MIN = 8388608;    // 8 MiB, unless the time slice asks for less.
	const PART_MAX = 536870912;  // 512 MiB.
	const TARGET   = 10.0;       // Seconds per part.

	const MAX_RESTARTS = 3; // Uploads started again because the storage forgot them.

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
		$name             = basename( $path );
		$where            = self::where( $storage );
		$state            = self::saved_state( $job, $storage, $where, $name, $size );
		$job->bytes_total = $size;

		try {
			$driver = Storages::driver( $storage );
			if ( null !== $state && ( $state['where'] !== $where || $state['name'] !== $name || (int) $state['size'] !== $size ) ) {
				// The storage settings (or the file) changed during the upload: drop the old upload and start again.
				self::abort( $driver, (array) $state['driver'] );
				$state = null;
			}
			if ( null === $state ) {
				$job->data['upload'] = array(
					'where'  => $where,
					'name'   => $name,
					'size'   => $size,
					'driver' => $driver->upload_open( $name, $size ),
					'speed'  => 0.0,
					'last'   => 0,
				);
				return false; // Checkpoint the upload now, so a crash cannot leave an unknown upload behind.
			}
			// What the storage really has: bytes may have arrived after the last checkpoint.
			$state['driver'] = $driver->upload_sync( (array) $state['driver'], $size );

			$first = true;
			do {
				$offset = (int) $state['driver']['offset'];
				if ( $offset >= $size ) {
					break;
				}
				$length = $driver->chunk_length( (array) $state['driver'], $size, self::wanted( (float) $state['speed'], (int) $state['last'], $context->remaining() ) );
				if ( ! $first && $state['speed'] > 0 && $length > $state['speed'] * $context->remaining() * 0.8 ) {
					break; // The next chunk would not fit in this slice: checkpoint first.
				}
				$first               = false;
				$start               = microtime( true );
				$state['driver']     = $driver->upload_chunk( (array) $state['driver'], $path, $length, $size );
				$sent                = max( 1, (int) $state['driver']['offset'] - $offset );
				$seconds             = max( 0.001, microtime( true ) - $start );
				$state['last']       = $sent;
				$state['speed']      = $state['speed'] > 0 ? 0.5 * $state['speed'] + 0.5 * $sent / $seconds : $sent / $seconds;
				$job->data['upload'] = $state;
				$job->bytes_done     = (int) $state['driver']['offset'];
				$context->report_progress();
			} while ( $context->should_continue() );

			$job->data['upload'] = $state;
			if ( (int) $state['driver']['offset'] < $size ) {
				return false;
			}
			$result = $driver->upload_close( (array) $state['driver'], $size );
		} catch ( RemoteException $e ) {
			if ( 'UploadExpired' !== $e->error_code ) {
				throw new JobException( sprintf( 'Upload to "%s" failed: %s', (string) $storage['name'], $e->getMessage() ) );
			}
			$restarts = (int) ( $job->data['upload_restarts'] ?? 0 ) + 1;
			if ( $restarts > self::MAX_RESTARTS ) {
				throw new JobException( sprintf( 'Upload to "%s" failed: the storage kept dropping the unfinished upload.', (string) $storage['name'] ) );
			}
			$context->log( 'The storage no longer knows the unfinished upload; starting it again.' );
			$job->data['upload_restarts'] = $restarts;
			unset( $job->data['upload'] );
			return false;
		}

		unset( $job->data['upload'], $job->data['upload_restarts'] );
		$label               = StorageOptions::key( $storage, $name );
		$job->data['remote'] = array(
			'storage' => (string) $storage['id'],
			'name'    => (string) $storage['name'],
			'key'     => $result['key'],
			'label'   => $label,
			'size'    => $size,
		);
		$job->bytes_done     = $size;
		$context->log( sprintf( 'Uploaded %s (%d bytes) to "%s" as %s.', $name, $size, (string) $storage['name'], $label ) );

		if ( ! empty( $remote['delete_local'] ) && 'backup' === $job->type ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plain PHP in jobs.
			$job->data['archive']['deleted_local'] = true;
			$context->log( 'Deleted the copy on this server, as asked.' );
		}
		return true;
	}

	/**
	 * Aborts an unfinished upload of a cancelled job.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function discard( Job $job ): void {
		$state   = (array) ( $job->data['upload'] ?? array() );
		$storage = Storages::get( (string) ( $job->options['remote']['storage'] ?? '' ) );
		if ( empty( $state['driver'] ) || null === $storage ) {
			return;
		}
		try {
			self::abort( Storages::driver( $storage ), (array) $state['driver'] );
		} catch ( RemoteException $e ) {
			unset( $e );
		}
	}

	/**
	 * The checkpointed upload, or null. Converts the multipart state saved by
	 * version 0.x before the driver refactor (key, id, parts, offset), so an
	 * S3 upload that was running during a plugin update continues.
	 *
	 * @param Job                 $job     Job.
	 * @param array<string,mixed> $storage Storage.
	 * @param string              $where   Where uploads of the storage go.
	 * @param string              $name    File name.
	 * @param int                 $size    Bytes.
	 * @return array<string,mixed>|null
	 */
	private static function saved_state( Job $job, array $storage, string $where, string $name, int $size ): ?array {
		$saved = $job->data['upload'] ?? null;
		if ( ! is_array( $saved ) ) {
			return null;
		}
		if ( isset( $saved['driver'] ) ) {
			return $saved;
		}
		if ( '' === (string) ( $saved['id'] ?? '' ) || 'gdrive' === ( $storage['provider'] ?? '' ) ) {
			return null; // Nothing unfinished in the storage.
		}
		$same = (string) ( $saved['key'] ?? '' ) === StorageOptions::key( $storage, $name );
		return array(
			'where'  => $same ? $where : '',
			'name'   => $name,
			'size'   => $size,
			'driver' => array(
				'key'    => (string) ( $saved['key'] ?? '' ),
				'id'     => (string) $saved['id'],
				'parts'  => (array) ( $saved['parts'] ?? array() ),
				'offset' => (int) ( $saved['offset'] ?? 0 ),
			),
			'speed'  => (float) ( $saved['speed'] ?? 0 ),
			'last'   => (int) ( $saved['last'] ?? 0 ),
		);
	}

	/**
	 * Bytes worth sending next: about TARGET seconds at the measured speed
	 * (8 MiB to 512 MiB), growing at most fourfold per request, and fitting
	 * the time left in the slice. Drivers round it to their own rules.
	 *
	 * @param float $speed     Measured bytes per second (0 before the first chunk).
	 * @param int   $last      Bytes of the previous chunk (0 for none).
	 * @param float $remaining Seconds left in the slice.
	 * @return int
	 */
	public static function wanted( float $speed, int $last, float $remaining ): int {
		$wanted = $speed > 0 ? (int) ( $speed * self::TARGET ) : self::PART_MIN;
		$wanted = max( self::PART_MIN, min( self::PART_MAX, $wanted ) );
		if ( $last > 0 ) {
			$wanted = min( $wanted, 4 * $last ); // One fast request says little about the link.
		}
		if ( $speed > 0 ) {
			$wanted = min( $wanted, max( 262144, (int) ( $speed * $remaining * 0.8 ) ) );
		}
		return $wanted;
	}

	/**
	 * Where uploads of a storage go; an upload started elsewhere cannot continue after a change.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return string
	 */
	private static function where( array $storage ): string {
		return md5( implode( "\n", array( (string) $storage['provider'], (string) ( $storage['endpoint'] ?? '' ), (string) ( $storage['bucket'] ?? '' ), (string) $storage['prefix'], (string) ( $storage['client_id'] ?? '' ) ) ) );
	}

	/**
	 * Abandons an upload, best effort.
	 *
	 * @param \Founders\Migration\Remote\Driver $driver Driver.
	 * @param array<string,mixed>               $state  Driver state.
	 * @return void
	 */
	private static function abort( $driver, array $state ): void {
		try {
			$driver->upload_abort( $state );
		} catch ( RemoteException $e ) {
			unset( $e ); // Storages also expire unfinished uploads (S3 lifecycle rules, Drive after a week).
		}
	}
}
