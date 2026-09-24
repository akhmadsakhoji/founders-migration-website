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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Lock;
use Founders\Migration\Job\Runner;
use Founders\Migration\Model\Export\BackupOptions;
use Founders\Migration\Model\Import\RestoreDatabase;
use Founders\Migration\Model\Import\RestoreOptions;
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
	 * Creates a .fmw backup in the backups folder.
	 *
	 * Resumable: if it stops (Ctrl+C, lost SSH session, server restart), run
	 * `wp fmw resume <job_id>`. Exit codes: 0 done, 1 failed, 3 stopped.
	 *
	 * ## OPTIONS
	 *
	 * [--exclude-spam-comments]
	 * : Leave out spam comments and their meta.
	 *
	 * [--exclude-post-revisions]
	 * : Leave out post revisions and their meta.
	 *
	 * [--exclude-transients]
	 * : Leave out transients from the options (and network meta) tables.
	 *
	 * [--exclude-media]
	 * : Leave out uploads.
	 *
	 * [--exclude-themes]
	 * : Leave out all themes.
	 *
	 * [--exclude-inactive-themes]
	 * : Leave out themes that are not active.
	 *
	 * [--exclude-muplugins]
	 * : Leave out must-use plugins.
	 *
	 * [--exclude-plugins]
	 * : Leave out all plugins.
	 *
	 * [--exclude-inactive-plugins]
	 * : Leave out plugins that are not active.
	 *
	 * [--exclude-cache]
	 * : Leave out cache folders (cache, et-cache, litespeed).
	 *
	 * [--exclude-database]
	 * : Leave out the database.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma-separated table names to leave out.
	 *
	 * [--exclude-paths=<patterns>]
	 * : Comma-separated patterns relative to wp-content, for example "uploads/old/*".
	 *
	 * [--part-size=<size>]
	 * : Target size of each part before compression, from 128M to 4G.
	 * ---
	 * default: 1G
	 * ---
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * [--porcelain]
	 * : Print only the backup file name.
	 *
	 * [--password=<password>]
	 * : Encrypt the backup. Planned for phase 2.
	 *
	 * [--sites=<ids>]
	 * : Back up selected subsites only. Planned for phase 3.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw backup
	 *     wp fmw backup --exclude-cache --exclude-post-revisions
	 *     wp fmw backup --exclude-media --porcelain
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function backup( $args, $assoc_args ) {
		if ( isset( $assoc_args['password'] ) ) {
			$this->planned( 'backup --password', 2 );
		}
		if ( isset( $assoc_args['sites'] ) ) {
			$this->planned( 'backup --sites', 3 );
		}

		try {
			$options = BackupOptions::from_flags( $assoc_args );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		Paths::ensure_all();
		$porcelain = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );
		$job       = Jobs::store()->create( 'backup', $options );
		$this->run_job( $job, $porcelain || ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ), $porcelain );
	}

	/**
	 * Restores a .fmw backup onto this site, replacing its files and database.
	 *
	 * The database is imported into temporary tables and switched in with one
	 * atomic rename at the end, so a restore that fails or is cancelled before
	 * that point leaves the site's database untouched. URLs and paths are
	 * replaced for the new location, serialized data included. This plugin's
	 * own folder is never overwritten, and files that are not in the backup are
	 * kept. Resumable: `wp fmw resume <job_id>`.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name (in the backups folder) or path.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * [--keep-old-tables]
	 * : Keep the replaced tables as fmwold_* (remove later with `wp fmw cleanup --tables`).
	 *
	 * [--exclude-email-replace]
	 * : Do not change e-mail addresses at the old domain.
	 *
	 * [--skip-space-check]
	 * : Start even if the free disk space looks too small.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw restore example.com-20260924-180000-a1b2c3.fmw
	 *     wp fmw restore /backups/site.fmw --yes --keep-old-tables
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		if ( '.wpress' === strtolower( substr( $args[0], -7 ) ) ) {
			WP_CLI::error( sprintf( 'Importing All-in-One WP Migration (.wpress) backups is planned for phase 2 and is not available in %s yet.', FMWP_VERSION ) );
		}

		$archive = $this->open_archive( $args[0] );
		try {
			$manifest = $archive->manifest();
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$site = (array) ( $manifest['site'] ?? array() );
		WP_CLI::confirm(
			sprintf(
				'Restore %s (%s, created %s) onto %s? This replaces this site\'s files and database.',
				basename( $args[0] ),
				(string) ( $site['home_url'] ?? '?' ),
				(string) ( $manifest['created_at'] ?? '?' ),
				home_url()
			),
			$assoc_args
		);

		Paths::ensure_all();
		$path = Backups::find( $args[0] ) ?? (string) realpath( $args[0] );
		$job  = Jobs::store()->create( 'restore', RestoreOptions::build( $path, $assoc_args ) );
		$this->run_job( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
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
	 * [--tables]
	 * : Also drop leftover fmwtmp_* and fmwold_* tables from restores.
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

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'tables', false ) ) {
			foreach ( $store->all() as $job ) {
				if ( 'restore' === $job->type && Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
					WP_CLI::error( sprintf( 'Restore job %s is running; its tables cannot be removed now.', $job->id ) );
				}
			}
			$restore = new RestoreDatabase();
			$tables  = array_merge( $restore->tables( RestoreDatabase::TMP ), $restore->tables( RestoreDatabase::OLD ) );
			foreach ( $tables as $table ) {
				WP_CLI::log( ( $dry_run ? 'Would drop ' : 'Dropping ' ) . $table );
			}
			if ( ! $dry_run ) {
				$restore->drop( $tables );
			}
			WP_CLI::success( sprintf( $dry_run ? '%d table(s) would be dropped.' : '%d table(s) dropped.', count( $tables ) ) );
		}
	}

	/**
	 * Checks every part of a backup against the SHA-256 checksums in its manifest.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name (in the backups folder) or path.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw verify example.com-20260924-180000-a1b2c3.fmw
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) {
		$archive  = $this->open_archive( $args[0] );
		$bar      = WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) ? new ProgressBar() : null;
		$problems = $archive->verify(
			static function ( int $done, int $total ) use ( $bar ) {
				if ( null !== $bar ) {
					$bar->update( 'Verify', $done, $total );
				}
			}
		);
		if ( null !== $bar ) {
			$bar->finish();
		}

		if ( $problems ) {
			foreach ( $problems as $problem ) {
				WP_CLI::warning( $problem );
			}
			WP_CLI::error( sprintf( '%s failed verification (%d problem(s)).', basename( $args[0] ), count( $problems ) ) );
		}
		WP_CLI::success( sprintf( 'All %d parts of %s are intact.', count( $archive->manifest()['parts'] ), basename( $args[0] ) ) );
	}

	/**
	 * Shows what a backup contains, without reading its parts.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name (in the backups folder) or path.
	 *
	 * [--format=<format>]
	 * : table for a summary, json for the full manifest.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw inspect example.com-20260924-180000-a1b2c3.fmw
	 *     wp fmw inspect example.com-20260924-180000-a1b2c3.fmw --format=json
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function inspect( $args, $assoc_args ) {
		try {
			$manifest = $this->open_archive( $args[0] )->manifest();
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( 'json' === WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$site   = (array) ( $manifest['site'] ?? array() );
		$totals = (array) ( $manifest['totals'] ?? array() );
		$db     = (array) ( $site['db'] ?? array() );
		$rows   = array(
			'Created'         => (string) ( $manifest['created_at'] ?? '' ),
			'Generator'       => (string) ( $manifest['generator'] ?? '' ),
			'Site URL'        => (string) ( $site['home_url'] ?? '' ),
			'WordPress'       => (string) ( $site['wp_version'] ?? '' ),
			'PHP'             => (string) ( $site['php_version'] ?? '' ),
			'Database server' => trim( ( $db['engine'] ?? '' ) . ' ' . ( $db['version'] ?? '' ), ' ' ),
			'Table prefix'    => (string) ( $site['table_prefix'] ?? '' ),
			'Multisite'       => ! empty( $site['multisite'] ) ? sprintf( 'yes (%d sites)', count( (array) ( $site['sites'] ?? array() ) ) ) : 'no',
			'Files'           => number_format( (int) ( $totals['files'] ?? 0 ) ),
			'Tables / rows'   => sprintf( '%d / %s', (int) ( $totals['tables'] ?? 0 ), number_format( (int) ( $totals['rows'] ?? 0 ) ) ),
			'Parts'           => (string) count( (array) $manifest['parts'] ),
			'Size'            => sprintf( '%s (%s before compression)', ProgressBar::bytes( (int) ( $totals['bytes_archived'] ?? 0 ) ), ProgressBar::bytes( (int) ( $totals['bytes_raw'] ?? 0 ) ) ),
			'Excluded'        => implode( ', ', (array) ( $manifest['options']['exclude'] ?? array() ) ),
		);

		$items = array();
		foreach ( $rows as $field => $value ) {
			$items[] = array(
				'field' => $field,
				'value' => '' === $value ? '-' : $value,
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Opens a backup by name (backups folder) or path, or stops with an error.
	 *
	 * @param string $file Name or path.
	 * @return FmwArchive
	 */
	private function open_archive( string $file ): FmwArchive {
		$path = Backups::find( $file );
		if ( null === $path && is_file( $file ) ) {
			$path = $file;
		}
		if ( null === $path ) {
			WP_CLI::error( sprintf( 'Backup "%s" not found in %s.', $file, fmwp_backups_path() ) );
		}
		try {
			$archive = new FmwArchive( (string) $path );
			$archive->header();
			return $archive;
		} catch ( ArchiveException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		exit( 1 ); // Not reached: WP_CLI::error() exits.
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
	 * @param bool $porcelain   Print only the result (backup file name).
	 * @return void
	 */
	private function run_job( Job $job, bool $no_progress, bool $porcelain = false ): void {
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

		if ( ! $porcelain ) {
			WP_CLI::log( sprintf( 'FMW %s · job %s', FMWP_VERSION, $job->id ) );
		}
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
			if ( 'restore' === $job->type ) {
				Jobs::store()->purge_work_files( $job->id );
				wp_cache_flush();
				delete_option( 'rewrite_rules' );
				WP_CLI::success( sprintf( 'Restore complete. %s now runs the restored site; log in with its accounts.', home_url() ) );
				return;
			}
			if ( isset( $job->data['archive']['path'] ) ) {
				Jobs::store()->purge_work_files( $job->id );
				if ( $porcelain ) {
					WP_CLI::line( (string) $job->data['archive']['name'] );
					return;
				}
				WP_CLI::success( sprintf( 'Backup created: %s (%s).', $job->data['archive']['path'], ProgressBar::bytes( (int) $job->data['archive']['bytes'] ) ) );
				return;
			}
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
