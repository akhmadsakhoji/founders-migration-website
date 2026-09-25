<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Job;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Job state must be written atomically with rename(); WP_Filesystem cannot guarantee that.

/**
 * Stores jobs on disk: <root>/<job id>/state.json, job.log, lock, cancel.
 */
final class JobStore {

	const STATE_FILE  = 'state.json';
	const LOG_FILE    = 'job.log';
	const LOCK_FILE   = 'lock';
	const CANCEL_FILE = 'cancel';

	/**
	 * Files named like this are small markers kept with the state and log (for example "a scheduled job was recorded").
	 */
	const MARKER_PREFIX = 'marker-';

	/**
	 * Jobs folder, no trailing slash.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Constructor.
	 *
	 * @param string $root Jobs folder (created on demand).
	 */
	public function __construct( string $root ) {
		$this->root = rtrim( $root, '/' );
	}

	/**
	 * Creates and saves a new pending job.
	 *
	 * @param string              $type    Job type.
	 * @param array<string,mixed> $options Options.
	 * @return Job
	 * @throws JobException When the job folder cannot be created.
	 */
	public function create( string $type, array $options = array() ): Job {
		$job = new Job( Ulid::generate(), $type, $options );
		$dir = $this->dir( $job->id );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new JobException( sprintf( 'Cannot create job folder %s.', $dir ) );
		}
		$this->save( $job );
		return $job;
	}

	/**
	 * Loads a job.
	 *
	 * @param string $id Job ID.
	 * @return Job
	 * @throws JobException When the ID is malformed or the job does not exist.
	 */
	public function load( string $id ): Job {
		$id   = strtoupper( $id );
		$path = $this->dir( $id ) . '/' . self::STATE_FILE;
		if ( ! is_file( $path ) ) {
			throw new JobException( sprintf( 'Job %s not found.', $id ) );
		}

		$state = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $state ) ) {
			throw new JobException( sprintf( 'Job %s has an unreadable state file.', $id ) );
		}

		$job = Job::from_array( $state );
		if ( $job->id !== $id ) {
			throw new JobException( sprintf( 'Job %s has a mismatching state file.', $id ) );
		}
		return $job;
	}

	/**
	 * Saves a job atomically: the state file is either the old or the new version, never half written.
	 *
	 * @param Job $job Job.
	 * @return void
	 * @throws JobException When the state cannot be written.
	 */
	public function save( Job $job ): void {
		$job->updated_at = time();

		$path = $this->dir( $job->id ) . '/' . self::STATE_FILE;
		$temp = $path . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$json = json_encode( $job->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			throw new JobException( sprintf( 'Cannot encode job state for %s.', $job->id ) );
		}
		$json .= "\n";

		if ( strlen( $json ) !== file_put_contents( $temp, $json ) || ! rename( $temp, $path ) ) {
			if ( file_exists( $temp ) ) {
				unlink( $temp );
			}
			throw new JobException( sprintf( 'Cannot write job state %s. The disk may be full.', $path ) );
		}
	}

	/**
	 * All jobs, newest first. Unreadable job folders are skipped.
	 *
	 * @return Job[]
	 */
	public function all(): array {
		if ( ! is_dir( $this->root ) ) {
			return array();
		}

		$jobs = array();
		foreach ( (array) scandir( $this->root, SCANDIR_SORT_DESCENDING ) as $name ) {
			if ( ! is_string( $name ) || ! Ulid::is_valid( $name ) ) {
				continue;
			}
			try {
				$jobs[] = $this->load( $name );
			} catch ( JobException $e ) {
				continue;
			}
		}
		return $jobs;
	}

	/**
	 * Folder of a job.
	 *
	 * @param string $id Job ID.
	 * @return string
	 * @throws JobException When the ID is malformed.
	 */
	public function dir( string $id ): string {
		if ( ! Ulid::is_valid( $id ) ) {
			throw new JobException( 'Invalid job ID.' );
		}
		return $this->root . '/' . $id;
	}

	/**
	 * Appends a timestamped line to the job log.
	 *
	 * @param string $id      Job ID.
	 * @param string $message Message.
	 * @return void
	 */
	public function log( string $id, string $message ): void {
		$line = gmdate( 'Y-m-d\TH:i:s\Z' ) . ' ' . str_replace( array( "\r", "\n" ), ' ', $message ) . "\n";
		file_put_contents( $this->dir( $id ) . '/' . self::LOG_FILE, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Last lines of the job log.
	 *
	 * @param string $id    Job ID.
	 * @param int    $lines Number of lines, 0 for all.
	 * @return string[]
	 */
	public function log_lines( string $id, int $lines = 50 ): array {
		$path = $this->dir( $id ) . '/' . self::LOG_FILE;
		if ( ! is_file( $path ) ) {
			return array();
		}
		$all = file( $path, FILE_IGNORE_NEW_LINES );
		$all = false === $all ? array() : $all;
		return $lines > 0 ? array_slice( $all, -$lines ) : $all;
	}

	/**
	 * Asks a running job to stop and cancel at its next checkpoint.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	public function request_cancel( string $id ): void {
		touch( $this->dir( $id ) . '/' . self::CANCEL_FILE );
	}

	/**
	 * Whether cancellation was requested.
	 *
	 * @param string $id Job ID.
	 * @return bool
	 */
	public function cancel_requested( string $id ): bool {
		return file_exists( $this->dir( $id ) . '/' . self::CANCEL_FILE );
	}

	/**
	 * Deletes everything in the job folder except its state, log and markers.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	public function purge_work_files( string $id ): void {
		$keep = array( self::STATE_FILE, self::LOG_FILE );
		$dir  = $this->dir( $id );
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( ! is_string( $name ) || '.' === $name || '..' === $name || in_array( $name, $keep, true ) || 0 === strpos( $name, self::MARKER_PREFIX ) ) {
				continue;
			}
			self::remove( $dir . '/' . $name );
		}
	}

	/**
	 * Deletes the whole job folder.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	public function delete( string $id ): void {
		self::remove( $this->dir( $id ) );
	}

	/**
	 * Recursively removes a path without following symlinks.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( is_string( $name ) && '.' !== $name && '..' !== $name ) {
				self::remove( $path . '/' . $name );
			}
		}
		rmdir( $path );
	}
}
