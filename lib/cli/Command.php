<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Cli;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Lock;
use Founders\Migration\Job\Runner;
use Founders\Migration\Requirements;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;
use WP_CLI;

/**
 * Backup, restore and migrate WordPress sites of any size.
 *
 * Command names and flags mirror `wp ai1wm`, so existing muscle memory works.
 *
 * ## EXAMPLES
 *
 *     wp fmw list-backups
 *     wp fmw backup --exclude-cache
 *     wp fmw restore example.com-20260924-180000-a1b2c3.fmw
 */
final class Command {

	/**
	 * Exit code of a job that stopped and can be resumed.
	 */
	const EXIT_RESUMABLE = 3;

	/**
	 * Lists backups in the backups folder, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * [--porcelain]
	 * : Print file names only, one per line.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw list-backups
	 *     wp fmw list-backups --format=json
	 *
	 * @subcommand list-backups
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function list_backups( $args, $assoc_args ) {
		$backups = Backups::all();

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			foreach ( $backups as $backup ) {
				WP_CLI::line( $backup['name'] );
			}
			return;
		}

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( ! $backups && 'table' === $format ) {
			WP_CLI::line( 'No backups found in ' . fmwp_backups_path() );
			return;
		}

		$rows = array_map(
			static function ( $backup ) use ( $format ) {
				return array(
					'name' => $backup['name'],
					'date' => wp_date( 'Y-m-d H:i:s', $backup['mtime'] ),
					'size' => 'table' === $format ? size_format( $backup['size'], 1 ) : $backup['size'],
				);
			},
			$backups
		);

		WP_CLI\Utils\format_items( $format, $rows, array( 'name', 'date', 'size' ) );
	}

	/**
	 * Deletes a backup from the backups folder.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name, as shown by `wp fmw list-backups`.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		$path = Backups::find( $args[0] );
		if ( null === $path ) {
			WP_CLI::error( sprintf( 'Backup "%s" not found in %s.', $args[0], fmwp_backups_path() ) );
		}

		WP_CLI::confirm( sprintf( 'Delete %s?', $args[0] ), $assoc_args );
		wp_delete_file( $path );

		if ( file_exists( $path ) ) {
			WP_CLI::error( sprintf( 'Could not delete %s.', $path ) );
		}
		WP_CLI::success( sprintf( 'Deleted %s.', $args[0] ) );
	}

	/**
	 * Shows server requirements and data folder status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw status
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		Paths::ensure_all();

		$rows = array(
			array(
				'item'  => 'FMW version',
				'value' => FMWP_VERSION,
			),
			array(
				'item'  => 'Archive format',
				'value' => (string) FMWP_FORMAT_VERSION,
			),
			array(
				'item'  => 'PHP',
				'value' => PHP_VERSION . ' (' . ( PHP_INT_SIZE * 8 ) . '-bit)',
			),
			array(
				'item'  => 'Backups folder',
				'value' => fmwp_backups_path() . ( wp_is_writable( fmwp_backups_path() ) ? '' : ' (NOT WRITABLE)' ),
			),
			array(
				'item'  => 'Storage folder',
				'value' => fmwp_storage_path() . ( wp_is_writable( fmwp_storage_path() ) ? '' : ' (NOT WRITABLE)' ),
			),
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'value' ) );

		foreach ( Requirements::errors() as $error ) {
			WP_CLI::warning( $error );
		}
		foreach ( Requirements::recommendations() as $note ) {
			WP_CLI::log( 'Note: ' . $note );
		}
	}

	/**
	 * Creates a backup. Planned for phase 1.
	 *
	 * ## OPTIONS
	 *
	 * [--<field>=<value>]
	 * : Flags follow `wp ai1wm backup` (see docs/format-v1.md and the README).
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function backup( $args, $assoc_args ) {
		$this->planned( 'backup', 1 );
	}

	/**
	 * Restores a .fmw (or .wpress) backup. Planned for phase 1 (.wpress in phase 2).
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Backup file name or path.
	 *
	 * [--<field>=<value>]
	 * : Flags follow `wp ai1wm restore`.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$this->planned( 'restore', 1 );
	}

	/**
	 * Lists backup, restore and pull jobs, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw jobs
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function jobs( $args, $assoc_args ) {
		$store  = Jobs::store();
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$jobs   = $store->all();

		if ( 'ids' === $format ) {
			$ids = array_map(
				static function ( Job $job ) {
					return $job->id;
				},
				$jobs
			);
			WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		$rows = array_map(
			static function ( Job $job ) use ( $store ) {
				$progress = $job->progress();
				$status   = $job->status;
				if ( Job::STATUS_RUNNING === $status && ! Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
					$status = 'interrupted';
				}
				return array(
					'id'       => $job->id,
					'type'     => $job->type,
					'status'   => $status,
					'phase'    => $job->phase,
					'progress' => null === $progress ? '' : (int) floor( $progress * 100 ) . '%',
					'updated'  => wp_date( 'Y-m-d H:i:s', $job->updated_at ),
				);
			},
			$jobs
		);

		if ( ! $rows && 'table' === $format ) {
			WP_CLI::line( 'No jobs.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'type', 'status', 'phase', 'progress', 'updated' ) );
	}

	/**
	 * Resumes an interrupted, paused or failed job from its last checkpoint.
	 *
	 * Exit codes: 0 completed, 1 failed, 3 stopped again and resumable.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID from `wp fmw jobs`.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default). Use --no-progress for cron and logs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw resume 01J8Z3K9QXAB12CD34EF56GH78
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function resume( $args, $assoc_args ) {
		$job = $this->load_job( $args[0] );
		if ( $job->is_finished() ) {
			WP_CLI::error( sprintf( 'Job %s is already %s.', $job->id, $job->status ) );
		}
		$this->run_job( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
	}

	/**
	 * Cancels a job and deletes its temporary files. A running job stops at its next checkpoint.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID from `wp fmw jobs`.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function cancel( $args, $assoc_args ) {
		$store = Jobs::store();
		$job   = $this->load_job( $args[0] );
		if ( $job->is_finished() ) {
			WP_CLI::error( sprintf( 'Job %s is already %s.', $job->id, $job->status ) );
		}

		WP_CLI::confirm( sprintf( 'Cancel job %s (%s)?', $job->id, $job->type ), $assoc_args );
		$store->request_cancel( $job->id );

		$lock = Lock::acquire( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE );
		if ( null === $lock ) {
			WP_CLI::success( sprintf( 'Cancellation requested. Job %s stops at its next checkpoint.', $job->id ) );
			return;
		}

		$job->status      = Job::STATUS_CANCELLED;
		$job->finished_at = time();
		$store->log( $job->id, 'Cancelled.' );
		$store->save( $job );
		$store->purge_work_files( $job->id );
		$lock->release();
		WP_CLI::success( sprintf( 'Job %s cancelled and its temporary files deleted.', $job->id ) );
	}

	/**
	 * Shows the log of a job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : Job ID from `wp fmw jobs`.
	 *
	 * [--lines=<lines>]
	 * : Number of lines from the end; 0 for the whole log.
	 * ---
	 * default: 50
	 * ---
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function log( $args, $assoc_args ) {
		$job   = $this->load_job( $args[0] );
		$lines = Jobs::store()->log_lines( $job->id, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'lines', 50 ) );
		foreach ( $lines as $line ) {
			WP_CLI::line( $line );
		}
		if ( Job::STATUS_FAILED === $job->status && null !== $job->error ) {
			WP_CLI::warning( 'Last error: ' . $job->error );
		}
	}

	/**
	 * Deletes storage of finished jobs, and of unfinished jobs untouched for a while.
	 *
	 * Completed and cancelled jobs are always removed. Failed, paused or
	 * interrupted jobs are removed when they have not been updated for
	 * --older-than days. Jobs that are running are never touched.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Age in days for unfinished jobs.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--dry-run]
	 * : Only list what would be deleted.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function cleanup( $args, $assoc_args ) {
		$store   = Jobs::store();
		$cutoff  = time() - DAY_IN_SECONDS * max( 0, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'older-than', 7 ) );
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$removed = 0;

		foreach ( $store->all() as $job ) {
			if ( Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
				continue;
			}
			if ( ! $job->is_finished() && $job->updated_at > $cutoff ) {
				continue;
			}
			WP_CLI::log( sprintf( '%s %s (%s, %s)', $dry_run ? 'Would delete' : 'Deleting', $job->id, $job->type, $job->status ) );
			if ( ! $dry_run ) {
				$store->delete( $job->id );
			}
			++$removed;
		}

		WP_CLI::success( sprintf( $dry_run ? '%d job(s) would be deleted.' : '%d job(s) deleted.', $removed ) );
	}

	/**
	 * Verifies the checksums of every part of a backup. Planned for phase 1.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Backup file name or path.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) {
		$this->planned( 'verify', 1 );
	}

	/**
	 * Shows the manifest of a backup. Planned for phase 1.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Backup file name or path.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function inspect( $args, $assoc_args ) {
		$this->planned( 'inspect', 1 );
	}

	/**
	 * Loads a job or stops with an error.
	 *
	 * @param string $id Job ID.
	 * @return Job
	 */
	private function load_job( string $id ): Job {
		try {
			return Jobs::store()->load( $id );
		} catch ( JobException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		exit( 1 ); // Not reached: WP_CLI::error() exits.
	}

	/**
	 * Runs a job in the foreground with progress output and ai1wm-style exit codes.
	 *
	 * @param Job  $job         Job.
	 * @param bool $no_progress Hide the progress bar.
	 * @return void
	 */
	private function run_job( Job $job, bool $no_progress ): void {
		$bar    = $no_progress ? null : new ProgressBar();
		$runner = new Runner(
			Jobs::store(),
			Jobs::registry(),
			static function ( Job $job ) use ( $bar ) {
				if ( null !== $bar && '' !== $job->phase ) {
					$bar->update( $job->phase, $job->bytes_done, $job->bytes_total );
				}
			}
		);

		WP_CLI::log( sprintf( 'FMW %s · job %s', FMWP_VERSION, $job->id ) );
		try {
			$runner->run( $job, Deadline::unlimited(), true );
		} catch ( JobException $e ) {
			WP_CLI::error( $e->getMessage() );
		} finally {
			if ( null !== $bar ) {
				$bar->finish();
			}
		}

		if ( Job::STATUS_COMPLETED === $job->status ) {
			WP_CLI::success( sprintf( 'Job %s completed.', $job->id ) );
			return;
		}
		if ( Job::STATUS_CANCELLED === $job->status ) {
			WP_CLI::warning( sprintf( 'Job %s was cancelled.', $job->id ) );
			WP_CLI::halt( 1 );
		}
		if ( Job::STATUS_FAILED === $job->status ) {
			WP_CLI::error( sprintf( 'Job %s failed: %s Fix the cause, then run `wp fmw resume %s`.', $job->id, rtrim( (string) $job->error, '.' ) . '.', $job->id ) );
		}
		WP_CLI::warning( sprintf( 'Job %s stopped. Continue with `wp fmw resume %s`.', $job->id, $job->id ) );
		WP_CLI::halt( self::EXIT_RESUMABLE );
	}

	/**
	 * Stops with a clear message for commands that are not built yet.
	 *
	 * @param string $command Subcommand.
	 * @param int    $phase   Roadmap phase.
	 * @return void
	 */
	private function planned( string $command, int $phase ): void {
		WP_CLI::error( sprintf( '`wp fmw %s` is planned for phase %d and is not available in %s yet.', $command, $phase, FMWP_VERSION ) );
	}
}
