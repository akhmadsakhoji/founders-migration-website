<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Pull;

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Archive\PasswordException;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Discardable;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\Step;
use Founders\Migration\Model\Remote\DownloadStep;
use Founders\Migration\Pull\PullException;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Uploads;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Resumable downloads need native file handles.

/**
 * Downloads the source's backup into this site's backups folder, resumably,
 * in byte ranges sized from the measured speed. A restart cuts the file
 * back to the last checkpoint, and every range must come from the same
 * version of the file (If-Match). A partial file shorter than the checkpoint
 * (a crash lost writes) continues from what is on disk. Afterwards the
 * archive is opened, and its password checked.
 *
 * Reads job options: archive_dir, secret_password. Reads job data: pull
 * (name, made). Sets job data: archive (name, path, bytes); sets job option
 * archive (for the restore steps that follow).
 */
final class PullDownloadStep implements Step, Discardable {

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
	 * @throws JobException When the download fails or the file is not a usable backup.
	 */
	public function run( Job $job, Context $context ): bool {
		$pull  = (array) ( $job->data['pull'] ?? array() );
		$name  = (string) ( $pull['name'] ?? '' );
		$dir   = rtrim( (string) ( $job->options['archive_dir'] ?? fmwp_backups_path() ), '/' );
		$state = (array) ( $job->data['pull_download'] ?? array() ) + array(
			'size'   => -1,
			'etag'   => '',
			'offset' => 0,
			'speed'  => 0.0,
			'last'   => 0,
			'file'   => '',
		);
		if ( '' === $name ) {
			throw new JobException( 'There is no backup to download (the remote backup step did not finish).' );
		}
		$client = RemoteBackupStep::client( $job );

		try {
			if ( $state['size'] < 0 ) {
				$info          = $client->backup( $name );
				$state['size'] = $info['size'];
				$state['etag'] = $info['etag'];
				$state['file'] = self::reserve( $dir, $name );
				if ( $state['size'] < 0 ) {
					throw new JobException( sprintf( 'The source site did not tell the size of %s.', $name ) );
				}
				$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not available on every host.
				if ( false !== $free && $free < $state['size'] && empty( $job->options['skip_space_check'] ) ) {
					throw new JobException( sprintf( 'Not enough disk space for the download: %d MB needed, %d MB free.', (int) ( $state['size'] / 1048576 ), (int) ( $free / 1048576 ) ) );
				}
				$job->data['pull_download'] = $state;
				$job->bytes_total           = (int) $state['size'];
				$job->bytes_done            = 0;
				$context->log( sprintf( 'Downloading %s (%d bytes) from %s.', $name, $state['size'], $client->url() ) );
				return false; // Checkpoint the reserved file name first, so a resume continues the same file.
			}
			$size             = (int) $state['size'];
			$partial          = $dir . '/' . $state['file'] . '.partial';
			$final            = $dir . '/' . $state['file'];
			$job->bytes_total = $size;
			$job->bytes_done  = (int) $state['offset'];

			clearstatcache();
			if ( ! file_exists( $partial ) && is_file( $final ) && filesize( $final ) === $size ) {
				return $this->finish( $job, $context, $final, $name ); // Renamed before a crash, but not checkpointed.
			}
			$handle = fopen( $partial, 'c+b' );
			if ( false === $handle ) {
				throw new JobException( sprintf( 'Cannot write %s.', $partial ) );
			}
			try {
				$have = (int) ( fstat( $handle )['size'] ?? 0 );
				if ( $have < (int) $state['offset'] ) {
					// Bytes the checkpoint counted never reached the disk (a crash): continue from what is there.
					$context->log( sprintf( 'The partial download has %d bytes, fewer than the %d checkpointed; continuing from %d.', $have, $state['offset'], $have ) );
					$state['offset'] = $have;
				}
				ftruncate( $handle, (int) $state['offset'] ); // Drop bytes written after the last checkpoint.
				fseek( $handle, (int) $state['offset'] );
				do {
					if ( (int) $state['offset'] >= $size ) {
						break;
					}
					$wanted = $state['speed'] > 0 ? (int) ( $state['speed'] * DownloadStep::TARGET ) : DownloadStep::CHUNK_MIN;
					$length = max( DownloadStep::CHUNK_MIN, min( DownloadStep::CHUNK_MAX, $wanted ) );
					if ( $state['speed'] > 0 ) {
						$length = min( $length, max( 1048576, (int) ( $state['speed'] * $context->remaining() * 0.8 ) ) ); // Fits the time slice.
					}
					if ( ! empty( $state['last'] ) ) {
						$length = min( $length, 4 * (int) $state['last'] ); // Grow step by step.
					}
					$length = min( $size - (int) $state['offset'], $length );
					$start  = microtime( true );
					$client->download_range( $name, (int) $state['offset'], (int) $state['offset'] + $length - 1, $handle, (string) $state['etag'] );
					fflush( $handle );
					$seconds                    = max( 0.001, microtime( true ) - $start );
					$state['offset']            = (int) $state['offset'] + $length;
					$state['last']              = $length;
					$state['speed']             = $state['speed'] > 0 ? 0.5 * $state['speed'] + 0.5 * $length / $seconds : $length / $seconds;
					$job->data['pull_download'] = $state;
					$job->bytes_done            = (int) $state['offset'];
					$context->report_progress();
				} while ( $context->should_continue() );
			} finally {
				fclose( $handle );
			}
		} catch ( PullException $e ) {
			if ( 412 === $e->status ) {
				// The file changed on the source: a resume downloads it again from the start.
				if ( '' !== (string) $state['file'] && is_file( $dir . '/' . $state['file'] . '.partial' ) ) {
					unlink( $dir . '/' . $state['file'] . '.partial' );
				}
				unset( $job->data['pull_download'] );
			}
			throw new JobException( 'Pull: ' . $e->getMessage() );
		}

		if ( (int) $state['offset'] < $size ) {
			return false;
		}
		clearstatcache( true, $partial );
		if ( filesize( $partial ) !== $size ) {
			throw new JobException( sprintf( '%s has %d bytes instead of %d.', $partial, (int) filesize( $partial ), $size ) );
		}
		if ( file_exists( $final ) ) {
			$final = $dir . '/' . Backups::unique_name( $state['file'] );
		}
		if ( ! rename( $partial, $final ) ) {
			throw new JobException( sprintf( 'Cannot move the download to %s.', $final ) );
		}
		return $this->finish( $job, $context, $final, $name );
	}

	/**
	 * Records the downloaded archive and checks that it opens. The backup on
	 * the source is deleted later, once the copy is proven intact (after the
	 * restore, or the Verify step of a download-only pull).
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @param string  $archive Downloaded archive.
	 * @param string  $name    Name on the source.
	 * @return bool
	 * @throws JobException When it is not a usable backup.
	 */
	private function finish( Job $job, Context $context, string $archive, string $name ): bool {
		unset( $job->data['pull_download'] );
		$job->data['archive']    = array(
			'name'  => basename( $archive ),
			'path'  => $archive,
			'bytes' => (int) filesize( $archive ),
		);
		$job->options['archive'] = $archive; // For the restore steps of a pull with restore.
		$context->log( sprintf( 'Downloaded %s to %s.', $name, $archive ) );
		self::check_archive( $job, $archive, $context );
		return true;
	}

	/**
	 * Deletes the partial download of a cancelled job.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function discard( Job $job ): void {
		$file = basename( (string) ( $job->data['pull_download']['file'] ?? '' ) );
		$dir  = rtrim( (string) ( $job->options['archive_dir'] ?? '' ), '/' );
		if ( '' !== $file && '' !== $dir && is_file( $dir . '/' . $file . '.partial' ) ) {
			unlink( $dir . '/' . $file . '.partial' );
		}
	}

	/**
	 * Opens the downloaded archive: it must be an FMW backup, and before a
	 * restore an encrypted one needs its password.
	 *
	 * @param Job     $job     Job.
	 * @param string  $path    Archive.
	 * @param Context $context Context.
	 * @return void
	 * @throws JobException When it is not a usable backup.
	 */
	private static function check_archive( Job $job, string $path, Context $context ): void {
		$restore = 'pull' !== $job->type;
		try {
			$archive  = new FmwArchive( $path );
			$password = $archive->encrypted() ? Secrets::open( $job->options['secret_password'] ?? null ) : null;
			if ( $archive->encrypted() && null === $password && ! $restore ) {
				$context->log( sprintf( '%s is encrypted; give its password when you restore it.', basename( $path ) ) );
				return;
			}
			$archive->manifest( $password );
		} catch ( PasswordException $e ) {
			throw new JobException( sprintf( 'The pulled backup %1$s is encrypted with a password this pull was not given (or a different one). It stays in the backups folder: restore it with `wp fmw restore %1$s --password`.', basename( $path ) ) );
		} catch ( ArchiveException $e ) {
			throw new JobException( sprintf( 'The pulled file is not a usable backup: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Picks a free file name in the backups folder and reserves it with an empty .partial.
	 *
	 * @param string $dir  Backups folder.
	 * @param string $name Name on the source.
	 * @return string
	 * @throws JobException When no name can be reserved.
	 */
	private static function reserve( string $dir, string $name ): string {
		$want = Uploads::sanitize_name( $name );
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$file   = Backups::unique_name( $want );
			$handle = file_exists( $dir . '/' . $file ) ? false : @fopen( $dir . '/' . $file . '.partial', 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fails when taken.
			if ( false !== $handle ) {
				fclose( $handle );
				return $file;
			}
			$want = (string) preg_replace( '/(\.[a-z]+)$/i', '-' . ( $attempt + 2 ) . '$1', Uploads::sanitize_name( $name ) );
		}
		throw new JobException( 'Cannot reserve a file name in the backups folder.' );
	}
}
