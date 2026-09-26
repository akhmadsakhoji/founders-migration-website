<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Schedule;

use Founders\Migration\Controller\AdminController;
use Founders\Migration\Controller\RestController;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Lock;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Model\Export\BackupOptions;
use Founders\Migration\Remote\RemoteException;
use Founders\Migration\Remote\Storages;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Runs scheduled backups: starts them when due, keeps them going, records
 * the result, applies retention and sends e-mail.
 *
 * Everything goes through tick(), which is safe to call at any time and
 * from anywhere (WP-Cron every five minutes, `wp fmw schedule run` from a
 * system cron, the end of a background slice): it records finished
 * scheduled jobs, continues a scheduled job that stopped (at most
 * MAX_STALLS times), and otherwise starts the first due schedule. Only one
 * job runs at a time, the same rule as for the admin screens, and no
 * scheduled backup starts while a restore or reset is unfinished: a backup
 * of a half-restored site must not push the good backups out by retention.
 */
final class Scheduler {

	const HOOK       = 'fmwp_schedule_tick';
	const INTERVAL   = 'fmwp_five_minutes';
	const MAX_STALLS = 12;
	const IDLE_AFTER = 120;
	const RECORDED   = JobStore::MARKER_PREFIX . 'schedule-recorded';

	/**
	 * Schedule storage.
	 *
	 * @return ScheduleStore
	 */
	public static function store(): ScheduleStore {
		return new ScheduleStore( fmwp_storage_path() . '/schedules.json' );
	}

	/**
	 * Registers WP-Cron hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'intervals' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes, and only while schedules exist.
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action(
			'init',
			static function (): void {
				if ( ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) && is_main_site() ) {
					self::sync_event();
				}
			}
		);
	}

	/**
	 * Adds the five-minute interval.
	 *
	 * @param array<string,array<string,mixed>> $schedules WP-Cron intervals.
	 * @return array<string,array<string,mixed>>
	 */
	public static function intervals( $schedules ): array {
		$schedules                   = (array) $schedules;
		$schedules[ self::INTERVAL ] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (Founders Migration Website)', 'founders-migration-website' ),
		);
		return $schedules;
	}

	/**
	 * Keeps the WP-Cron event only while there is something to do (call after changing schedules).
	 *
	 * @return void
	 */
	public static function sync_event(): void {
		if ( ! is_main_site() ) {
			switch_to_blog( get_main_site_id() ); // Schedules belong to the whole install: on a network, the main site runs them.
			self::sync_event();
			restore_current_blog();
			return;
		}
		$needed = false;
		foreach ( self::store()->all() as $schedule ) {
			if ( ! empty( $schedule['enabled'] ) || 'running' === ( $schedule['state']['last_status'] ?? '' ) ) {
				$needed = true;
				break;
			}
		}
		$next = wp_next_scheduled( self::HOOK );
		if ( $needed && false === $next ) {
			wp_schedule_event( time() + 60, self::INTERVAL, self::HOOK );
		} elseif ( ! $needed && false !== $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * The WP-Cron event: a tick, then one slice of the job it returns, then the background chain.
	 *
	 * @return void
	 */
	public static function cron(): void {
		if ( ! is_main_site() ) {
			wp_clear_scheduled_hook( self::HOOK ); // Left on a subsite by a development version.
			return;
		}
		$job = self::tick( time(), 'wp-cron' );
		if ( null === $job ) {
			return;
		}
		// Work a slice right here, so a host that blocks loopback requests still gets there, then hand over.
		$job = self::slice( $job, RestController::slice_seconds() );
		if ( null !== $job && ! $job->is_finished() && Job::STATUS_RUNNING === $job->status ) {
			Background::dispatch( $job );
		}
	}

	/**
	 * Records finished scheduled jobs, then picks the scheduled job to work on (continued or new).
	 *
	 * @param int    $now    Unix time.
	 * @param string $source What called it (wp-cron, cli, background), shown on the Schedules screen.
	 * @return Job|null A job that needs running, or null.
	 */
	public static function tick( int $now, string $source = 'wp-cron' ): ?Job {
		if ( ! Paths::ensure_all() ) {
			return null;
		}
		$lock = Lock::acquire( self::lock_path(), 60 );
		if ( null === $lock ) {
			return null; // Another tick is deciding right now.
		}
		try {
			return self::decide( $now, $source );
		} catch ( \RuntimeException $e ) {
			return null; // The schedules file is damaged or not writable; the Schedules screen shows it.
		} finally {
			$lock->release();
		}
	}

	/**
	 * Starts a schedule now ("Run now"), with the same rules as a due run.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @param string              $source   Who asked.
	 * @return Job
	 * @throws JobException When another job runs, a restore or reset is unfinished, or the backup cannot start.
	 */
	public static function run_now( array $schedule, string $source ): Job {
		if ( ! Paths::ensure_all() ) {
			throw new JobException( 'The backups or storage folder is not writable.' );
		}
		$lock = Lock::acquire( self::lock_path(), 60 );
		if ( null === $lock ) {
			throw new JobException( 'The scheduler is busy; try again in a moment.' );
		}
		try {
			$now  = time();
			$jobs = Jobs::store();
			foreach ( $jobs->all() as $job ) {
				if ( $job->is_finished() ) {
					continue;
				}
				if ( self::is_active( $job, $jobs, $now ) ) {
					throw new JobException( 'Another backup or restore is running. Try again when it has finished.' );
				}
				if ( Jobs::changes_site( $job->type ) ) {
					throw new JobException( sprintf( 'A restore or reset (job %s) stopped before the end. Continue or cancel it first.', $job->id ) );
				}
			}
			return self::start( $schedule, $now, $source );
		} finally {
			$lock->release();
		}
	}

	/**
	 * The body of tick(), under the tick lock.
	 *
	 * @param int    $now    Unix time.
	 * @param string $source Caller.
	 * @return Job|null
	 */
	private static function decide( int $now, string $source ): ?Job {
		$store = self::store();
		$store->update(
			static function ( array &$data ) use ( $now, $source ): void {
				$data['meta']['last_tick']        = $now;
				$data['meta']['last_tick_source'] = $source;
			}
		);

		$jobs    = Jobs::store();
		$stalled = null;
		$waiting = '';
		foreach ( $jobs->all() as $job ) {
			$id = (string) ( $job->options['schedule_id'] ?? '' );
			if ( '' === $id ) {
				if ( ! $job->is_finished() && self::is_active( $job, $jobs, $now ) ) {
					return null; // Someone else's job is running: wait for it.
				}
				if ( ! $job->is_finished() && Jobs::changes_site( $job->type ) ) {
					// A restore or reset stopped half-way: backing up that site could rotate out the good backups.
					$waiting = $job->id;
				}
				continue;
			}
			if ( $job->is_finished() || Job::STATUS_FAILED === $job->status ) {
				self::record( $job );
				continue;
			}
			if ( self::is_active( $job, $jobs, $now ) ) {
				return null; // Running (in the background chain or a browser).
			}
			$stalled = $stalled ?? $job;
		}

		if ( null !== $stalled ) {
			return self::continue_stalled( $stalled, $source );
		}

		$before = (string) ( $store->meta()['waiting_for'] ?? '' );
		if ( $before !== $waiting ) {
			$store->set_meta( 'waiting_for', $waiting );
		}
		if ( '' !== $waiting ) {
			return null;
		}
		foreach ( $store->all() as $schedule ) {
			if ( empty( $schedule['enabled'] ) || (int) ( $schedule['state']['next_run'] ?? 0 ) <= 0 || (int) $schedule['state']['next_run'] > $now ) {
				continue;
			}
			try {
				return self::start( $schedule, $now, $source );
			} catch ( \Throwable $e ) {
				self::start_failed( $schedule, $now, $e->getMessage() );
			}
		}
		return null;
	}

	/**
	 * Continues a scheduled job nobody is running, unless it made no progress MAX_STALLS times in a row.
	 *
	 * The counter lives in the schedules file, not in the job, so the tick never
	 * writes the state of a job another process may be running.
	 *
	 * @param Job    $job    Job.
	 * @param string $source Caller.
	 * @return Job|null
	 */
	private static function continue_stalled( Job $job, string $source ): ?Job {
		$jobs        = Jobs::store();
		$fingerprint = md5( (string) wp_json_encode( array( $job->step, $job->bytes_done, $job->cursor, $job->status ) ) );
		$stalls      = self::store()->update(
			static function ( array &$data ) use ( $job, $fingerprint ): int {
				$seen                   = (array) ( $data['meta']['stalls'][ $job->id ] ?? array() );
				$count                  = ( $seen['fingerprint'] ?? '' ) === $fingerprint ? (int) ( $seen['count'] ?? 0 ) + 1 : 1;
				$data['meta']['stalls'] = array(
					$job->id => array(
						'fingerprint' => $fingerprint,
						'count'       => $count,
					),
				); // Only the current job is tracked.
				return $count;
			}
		);

		if ( $stalls > self::MAX_STALLS ) {
			$lock = Lock::acquire( $jobs->dir( $job->id ) . '/' . JobStore::LOCK_FILE );
			if ( null === $lock ) {
				return null; // Someone picked it up after all.
			}
			try {
				$job         = $jobs->load( $job->id );
				$job->status = Job::STATUS_FAILED;
				$job->error  = sprintf( 'The backup made no progress in %d attempts (for example the server ends long requests or runs out of memory). Run `wp fmw schedule run` from a system cron, or check the job log.', self::MAX_STALLS );
				$jobs->save( $job );
			} finally {
				$lock->release();
			}
			$jobs->log( $job->id, (string) $job->error );
			self::record( $job );
			return null;
		}
		$jobs->log( $job->id, sprintf( 'Continued by the scheduler (%s).', $source ) );
		return $job;
	}

	/**
	 * A due schedule could not start: record it, move on to the next run, and tell someone.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @param int                 $now      Unix time.
	 * @param string              $error    Why.
	 * @return void
	 */
	private static function start_failed( array $schedule, int $now, string $error ): void {
		$current = self::store()->change(
			(string) $schedule['id'],
			static function ( array $current ) use ( $now, $error ): array {
				$current['state']['last_run']    = $now;
				$current['state']['last_job']    = '';
				$current['state']['last_status'] = Job::STATUS_FAILED;
				$current['state']['last_error']  = $error;
				$current['state']['next_run']    = ! empty( $current['enabled'] ) ? Recurrence::next( $current, $now, wp_timezone() ) : 0;
				return $current;
			}
		);
		if ( null !== $current ) {
			self::notify(
				$current,
				array(
					'job'    => '-',
					'status' => Job::STATUS_FAILED,
					'error'  => $error,
					'name'   => '',
					'bytes'  => 0,
				),
				array()
			);
		}
	}

	/**
	 * Starts a backup job for a schedule. Callers hold the tick lock (tick(), run_now()).
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @param int                 $now      Unix time.
	 * @param string              $source   Who started it.
	 * @return Job
	 * @throws JobException When the backup options cannot be built.
	 */
	private static function start( array $schedule, int $now, string $source ): Job {
		$id    = (string) $schedule['id'];
		$flags = (array) ( $schedule['flags'] ?? array() );
		if ( ! empty( $schedule['secret_password'] ) ) {
			$password = Secrets::open( $schedule['secret_password'] );
			if ( null === $password ) {
				throw new JobException( 'The saved password of this schedule cannot be read (the site\'s salts changed). Set it again.' );
			}
			$flags['password'] = $password;
		}
		if ( '' !== (string) ( $schedule['storage'] ?? '' ) ) {
			$flags['storage']      = (string) $schedule['storage'];
			$flags['delete-local'] = empty( $schedule['keep_local'] );
		}

		try {
			$options = BackupOptions::from_flags( $flags );
		} catch ( \InvalidArgumentException $e ) {
			throw new JobException( $e->getMessage() );
		}
		$options['schedule_id']   = $id;
		$options['schedule_name'] = (string) ( $schedule['name'] ?? '' );

		$job = Jobs::store()->create( 'backup', $options );
		Jobs::store()->log( $job->id, sprintf( 'Started by schedule "%s" (%s).', $options['schedule_name'], $source ) );
		self::store()->change(
			$id,
			static function ( array $current ) use ( $job, $now ): array {
				$current['state']['last_run']    = $now;
				$current['state']['last_job']    = $job->id;
				$current['state']['last_status'] = 'running';
				$current['state']['last_error']  = '';
				$current['state']['next_run']    = ! empty( $current['enabled'] ) ? Recurrence::next( $current, $now, wp_timezone() ) : 0;
				return $current;
			}
		);
		return $job;
	}

	/**
	 * Runs one slice of a job, then records it if it ended.
	 *
	 * @param Job   $job     Job.
	 * @param float $seconds Time budget.
	 * @return Job|null The job, or null when another process is running it.
	 */
	public static function slice( Job $job, float $seconds ): ?Job {
		try {
			$job = ( new Runner( Jobs::store(), Jobs::registry() ) )->run( $job, new Deadline( $seconds ) );
		} catch ( JobException $e ) {
			return null;
		}
		if ( $job->is_finished() || Job::STATUS_FAILED === $job->status ) {
			self::after( $job, true );
		}
		return $job;
	}

	/**
	 * After a scheduled job ended: record it, then start the next due schedule, if any.
	 *
	 * @param Job  $job      Job that completed, failed or was cancelled.
	 * @param bool $dispatch Continue the next job in the background (false when the caller runs it).
	 * @return Job|null The next job.
	 */
	public static function after( Job $job, bool $dispatch ): ?Job {
		if ( '' === (string) ( $job->options['schedule_id'] ?? '' ) ) {
			return null;
		}
		try {
			self::record( $job );
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Recorded by the next tick.
		}
		$next = self::tick( time(), 'background' );
		if ( null !== $next && $dispatch ) {
			Background::dispatch( $next );
		}
		return $next;
	}

	/**
	 * Records the end of a scheduled job exactly once: status, retention, e-mail.
	 *
	 * A failed job is then cancelled: its work files and the partial archive
	 * are deleted, so a full disk does not fill up further night after night.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public static function record( Job $job ): void {
		$jobs = Jobs::store();
		try {
			$job = $jobs->load( $job->id ); // The latest state.
		} catch ( JobException $e ) {
			return;
		}
		if ( ! $job->is_finished() && Job::STATUS_FAILED !== $job->status ) {
			return;
		}
		// Exactly once, even when a tick and the end of a background slice get here together.
		$marker = @fopen( $jobs->dir( $job->id ) . '/' . self::RECORDED, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Fails when already recorded.
		if ( false === $marker ) {
			return;
		}
		fclose( $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen().

		$id     = (string) ( $job->options['schedule_id'] ?? '' );
		$status = $job->status;
		$result = array(
			'job'    => $job->id,
			'status' => $status,
			'error'  => (string) $job->error,
			'name'   => (string) ( $job->data['archive']['name'] ?? '' ),
			'bytes'  => (int) ( $job->data['archive']['bytes'] ?? 0 ),
			'remote' => isset( $job->data['remote']['key'] ) ? $job->data['remote']['name'] . ': ' . ( $job->data['remote']['label'] ?? $job->data['remote']['key'] ) : '',
			'local'  => empty( $job->data['archive']['deleted_local'] ),
		);
		if ( Job::STATUS_COMPLETED === $status ) {
			$jobs->purge_work_files( $job->id );
		}

		// A finished archive on this server joins the local retention, even when the job failed later
		// (for example in the upload): otherwise every failed night would leave an untracked backup behind.
		$archive  = (string) ( $job->data['archive']['path'] ?? '' );
		$name     = '' !== $archive && is_file( $archive ) && empty( $job->data['archive']['deleted_local'] ) ? basename( $archive ) : '';
		$remote   = (array) ( $job->data['remote'] ?? array() );
		$needed   = self::archives_in_use( $jobs );
		$deleted  = array();
		$obsolete = array();
		$current  = self::store()->change(
			$id,
			static function ( array $schedule ) use ( $job, $status, $name, $remote, $needed, &$deleted, &$obsolete ): array {
				if ( (string) ( $schedule['state']['last_job'] ?? '' ) === $job->id || '' === (string) ( $schedule['state']['last_job'] ?? '' ) ) {
					$schedule['state']['last_status'] = $status;
					$schedule['state']['last_error']  = (string) $job->error;
				}
				if ( Job::STATUS_CANCELLED !== $status && '' !== $name ) {
					$list   = array_values( array_diff( (array) ( $schedule['state']['backups'] ?? array() ), array( $name ) ) );
					$list[] = $name;
					$keep   = (int) ( $schedule['keep'] ?? 0 );
					$extra  = count( $list ) - $keep;
					if ( $keep > 0 && $extra > 0 ) {
						foreach ( array_splice( $list, 0, $extra ) as $old ) { // Oldest first.
							if ( in_array( (string) $old, $needed, true ) ) {
								array_unshift( $list, $old ); // An unfinished restore reads it: deleted by a later run.
							} else {
								$deleted[] = (string) $old;
							}
						}
					}
					$schedule['state']['backups'] = $list;
				}
				if ( Job::STATUS_COMPLETED === $status && ! empty( $remote['key'] ) ) {
					// Each upload is remembered with its storage, so a schedule moved to another storage still prunes the old one.
					$entry = array(
						'storage' => (string) $remote['storage'],
						'key'     => (string) $remote['key'],
						'label'   => (string) ( $remote['label'] ?? $remote['key'] ),
					);
					$list  = array();
					foreach ( (array) ( $schedule['state']['remote_backups'] ?? array() ) as $item ) {
						$item = is_array( $item ) ? $item : array(
							'storage' => $entry['storage'],
							'key'     => (string) $item,
						);
						if ( $item['storage'] !== $entry['storage'] || $item['key'] !== $entry['key'] ) {
							$list[] = $item;
						}
					}
					$list[] = $entry;
					$keep   = (int) ( $schedule['remote_keep'] ?? 0 );
					$extra  = count( $list ) - $keep;
					if ( $keep > 0 && $extra > 0 ) {
						$obsolete = array_splice( $list, 0, $extra ); // Oldest first; put back below if the delete fails.
					}
					$schedule['state']['remote_backups'] = $list;
				}
				return $schedule;
			}
		);

		foreach ( $deleted as $old ) {
			$gone = Backups::delete( $old ) || null === Backups::get( $old );
			$jobs->log( $job->id, sprintf( $gone ? 'Retention: deleted the older backup %s.' : 'Retention: could not delete %s.', $old ) );
		}
		$failed = array();
		foreach ( $obsolete as $item ) {
			$storage = Storages::get( (string) $item['storage'] );
			try {
				if ( null === $storage ) {
					$jobs->log( $job->id, sprintf( 'Retention: could not delete the upload %s: its storage was removed from this site.', $item['key'] ) );
					continue; // Nothing this site can do; forget it.
				}
				Storages::driver( $storage )->delete( (string) $item['key'] );
				$label     = (string) ( $item['label'] ?? $item['key'] );
				$deleted[] = $label . ' (' . $storage['name'] . ')';
				$jobs->log( $job->id, sprintf( 'Retention: deleted the older upload %s from "%s".', $label, $storage['name'] ) );
			} catch ( RemoteException $e ) {
				$failed[] = $item;
				$jobs->log( $job->id, sprintf( 'Retention: could not delete the upload %s (tried again after the next backup): %s', $item['key'], $e->getMessage() ) );
			}
		}
		if ( $failed ) {
			self::store()->change(
				$id,
				static function ( array $schedule ) use ( $failed ): array {
					$schedule['state']['remote_backups'] = array_merge( $failed, (array) ( $schedule['state']['remote_backups'] ?? array() ) );
					return $schedule;
				}
			);
		}
		if ( Job::STATUS_FAILED === $status ) {
			$jobs->request_cancel( $job->id ); // Deletes the work files; the next run starts afresh.
			try {
				( new Runner( $jobs, Jobs::registry() ) )->run( $job, new Deadline( 5.0 ) );
			} catch ( JobException $e ) {
				unset( $e ); // Applied by whoever holds the job.
			}
		}
		self::delete_old_jobs( $id, $job->id, $jobs );

		if ( null !== $current ) {
			$result['kept'] = Job::STATUS_FAILED === $status ? $name : '';
			self::notify( $current, $result, $deleted );
		}
	}

	/**
	 * Backup files that unfinished restores read.
	 *
	 * @param JobStore $jobs Store.
	 * @return string[]
	 */
	private static function archives_in_use( JobStore $jobs ): array {
		$names = array();
		foreach ( $jobs->all() as $job ) {
			if ( ! $job->is_finished() && Jobs::is_restore( $job->type ) ) {
				$names[] = basename( (string) ( $job->options['archive'] ?? '' ) );
			}
		}
		return $names;
	}

	/**
	 * The tick lock file.
	 *
	 * @return string
	 */
	private static function lock_path(): string {
		return fmwp_storage_path() . '/schedules.tick.lock';
	}

	/**
	 * Deletes earlier finished jobs of a schedule; the last one is kept for its log.
	 *
	 * @param string   $schedule Schedule ID.
	 * @param string   $keep     Job to keep.
	 * @param JobStore $jobs     Store.
	 * @return void
	 */
	private static function delete_old_jobs( string $schedule, string $keep, JobStore $jobs ): void {
		foreach ( $jobs->all() as $job ) {
			$owner = (string) ( $job->options['schedule_id'] ?? '' );
			if ( $keep !== $job->id && $owner === $schedule && $job->is_finished() && is_file( $jobs->dir( $job->id ) . '/' . self::RECORDED ) ) {
				$jobs->delete( $job->id );
			}
		}
	}

	/**
	 * Sends the result by e-mail, as the schedule asks.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @param array<string,mixed> $result   Job result: job, status, error, name, bytes, remote, local.
	 * @param string[]            $deleted  Backups removed by retention.
	 * @return void
	 */
	private static function notify( array $schedule, array $result, array $deleted ): void {
		$notify = (string) ( $schedule['notify'] ?? 'failure' );
		$failed = Job::STATUS_FAILED === $result['status'];
		if ( Job::STATUS_CANCELLED === $result['status'] ) {
			return; // Cancelled by someone: they know.
		}
		if ( 'never' === $notify || ( 'failure' === $notify && ! $failed ) ) {
			return;
		}
		$to = '' !== (string) ( $schedule['email'] ?? '' ) ? (string) $schedule['email'] : (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}

		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject = $failed
			/* translators: 1: site name, 2: schedule name. */
			? sprintf( __( '[%1$s] Scheduled backup FAILED: %2$s', 'founders-migration-website' ), $site, $schedule['name'] )
			/* translators: 1: site name, 2: schedule name. */
			: sprintf( __( '[%1$s] Scheduled backup completed: %2$s', 'founders-migration-website' ), $site, $schedule['name'] );

		$lines = array(
			/* translators: %s: site URL. */
			sprintf( __( 'Site: %s', 'founders-migration-website' ), home_url() ),
			/* translators: %s: schedule name. */
			sprintf( __( 'Schedule: %s', 'founders-migration-website' ), $schedule['name'] ),
			/* translators: %s: job ID. */
			sprintf( __( 'Job: %s', 'founders-migration-website' ), $result['job'] ),
		);
		if ( $failed ) {
			/* translators: %s: error message. */
			$lines[] = sprintf( __( 'Error: %s', 'founders-migration-website' ), $result['error'] );
			$lines[] = ! empty( $result['kept'] )
				/* translators: %s: file name. */
				? sprintf( __( 'The backup itself was created and is kept on this server (%s); only the upload failed. The next run is at its usual time.', 'founders-migration-website' ), $result['kept'] )
				: __( 'The partial backup was deleted. The next run is at its usual time; see the job log with: wp fmw log <job>', 'founders-migration-website' );
		} else {
			/* translators: 1: file name, 2: size. */
			$lines[] = sprintf( __( 'Backup: %1$s (%2$s)', 'founders-migration-website' ), $result['name'], size_format( $result['bytes'] ) );
			if ( ! empty( $result['remote'] ) ) {
				/* translators: %s: storage name and object key. */
				$lines[] = sprintf( __( 'Uploaded to %s', 'founders-migration-website' ), $result['remote'] );
				if ( empty( $result['local'] ) ) {
					$lines[] = __( 'The copy on the server was deleted after the upload, as the schedule asks.', 'founders-migration-website' );
				}
			}
			foreach ( $deleted as $old ) {
				/* translators: %s: file name. */
				$lines[] = sprintf( __( 'Deleted by retention: %s', 'founders-migration-website' ), $old );
			}
		}
		$lines[] = '';
		$lines[] = add_query_arg( 'page', AdminController::SLUG_SCHEDULES, is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * When a schedule runs, in words ("Every Monday at 02:00").
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @return string
	 */
	public static function describe( array $schedule ): string {
		global $wp_locale;
		$time = (string) ( $schedule['time'] ?? '00:00' );
		switch ( (string) ( $schedule['frequency'] ?? 'daily' ) ) {
			case 'hourly':
				/* translators: %s: minute (two digits). */
				return sprintf( __( 'Every hour at minute %s', 'founders-migration-website' ), substr( $time, -2 ) );
			case 'weekly':
				$day = (int) ( $schedule['weekday'] ?? 0 );
				/* translators: 1: day of the week, 2: time. */
				return sprintf( __( 'Every %1$s at %2$s', 'founders-migration-website' ), $wp_locale ? $wp_locale->get_weekday( $day ) : (string) $day, $time );
			case 'monthly':
				/* translators: 1: day of the month, 2: time. */
				return sprintf( __( 'Monthly on day %1$d at %2$s', 'founders-migration-website' ), (int) ( $schedule['monthday'] ?? 1 ), $time );
			default:
				/* translators: %s: time. */
				return sprintf( __( 'Every day at %s', 'founders-migration-website' ), $time );
		}
	}

	/**
	 * How the scheduler is being woken up, for the Schedules screen and `wp fmw schedule list`.
	 *
	 * @return array{last_tick:int,source:string,wp_cron_disabled:bool,late:bool,waiting_for:string}
	 */
	public static function health(): array {
		$meta    = self::store()->meta();
		$last    = (int) ( $meta['last_tick'] ?? 0 );
		$enabled = false;
		foreach ( self::store()->all() as $schedule ) {
			$enabled = $enabled || ! empty( $schedule['enabled'] );
		}
		return array(
			'last_tick'        => $last,
			'source'           => (string) ( $meta['last_tick_source'] ?? '' ),
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'late'             => $enabled && time() - max( $last, self::oldest_schedule() ) > 1800,
			'waiting_for'      => (string) ( $meta['waiting_for'] ?? '' ),
		);
	}

	/**
	 * Creation time of the oldest schedule (a new site has not had a tick yet).
	 *
	 * @return int
	 */
	private static function oldest_schedule(): int {
		$oldest = time();
		foreach ( self::store()->all() as $schedule ) {
			$oldest = min( $oldest, (int) ( $schedule['created_at'] ?? time() ) );
		}
		return $oldest;
	}

	/**
	 * Whether a job is being worked on (lock held, or running or new and updated recently).
	 *
	 * @param Job      $job  Job.
	 * @param JobStore $jobs Store.
	 * @param int      $now  Unix time.
	 * @return bool
	 */
	private static function is_active( Job $job, JobStore $jobs, int $now ): bool {
		if ( Lock::is_held( $jobs->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
			return true;
		}
		// Running between two slices, or just created and about to be picked up by a browser.
		return in_array( $job->status, array( Job::STATUS_RUNNING, Job::STATUS_PENDING ), true ) && $now - $job->updated_at < self::IDLE_AFTER;
	}
}
