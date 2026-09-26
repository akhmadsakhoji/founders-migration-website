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

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Runs a job's steps under a lock, checkpointing after every slice.
 *
 * One call to run() works until the job completes, fails, is cancelled, is
 * interrupted (Ctrl+C / SIGTERM) or the deadline passes. Afterwards the job
 * status tells the caller what happened:
 *
 * - completed: done.
 * - failed:    $job->error says why; resume() retries the failing slice.
 * - cancelled: work files were removed.
 * - paused:    stopped by a signal; resume later.
 * - running:   the deadline passed (web request); call run() again.
 */
final class Runner {

	/**
	 * Job storage.
	 *
	 * @var JobStore
	 */
	private $store;

	/**
	 * Job types.
	 *
	 * @var StepRegistry
	 */
	private $registry;

	/**
	 * Called after every checkpoint with the job.
	 *
	 * @var callable(Job): void|null
	 */
	private $on_checkpoint;

	/**
	 * Slice length in seconds.
	 *
	 * @var float
	 */
	private $slice_seconds;

	/**
	 * Set by a signal handler or request_stop().
	 *
	 * @var bool
	 */
	private $stop_requested = false;

	/**
	 * Constructor.
	 *
	 * @param JobStore                 $store         Job storage.
	 * @param StepRegistry             $registry      Job types.
	 * @param callable(Job): void|null $on_checkpoint Progress callback.
	 * @param float                    $slice_seconds Seconds between checkpoints.
	 */
	public function __construct( JobStore $store, StepRegistry $registry, ?callable $on_checkpoint = null, float $slice_seconds = Context::SLICE_SECONDS ) {
		$this->store         = $store;
		$this->registry      = $registry;
		$this->on_checkpoint = $on_checkpoint;
		$this->slice_seconds = $slice_seconds;
	}

	/**
	 * Asks the runner to stop at the next checkpoint (status becomes paused).
	 *
	 * @return void
	 */
	public function request_stop(): void {
		$this->stop_requested = true;
	}

	/**
	 * Runs or resumes a job.
	 *
	 * @param Job      $job            Job loaded from the store.
	 * @param Deadline $deadline       Time budget.
	 * @param bool     $handle_signals Install SIGINT/SIGTERM handlers (WP-CLI).
	 * @return Job The same job, updated.
	 * @throws JobException When the job is finished or locked by another process.
	 */
	public function run( Job $job, Deadline $deadline, bool $handle_signals = false ): Job {
		if ( $job->is_finished() ) {
			throw new JobException( sprintf( 'Job %s is already %s.', $job->id, $job->status ) );
		}

		$dir  = $this->store->dir( $job->id );
		$lock = Lock::acquire( $dir . '/' . JobStore::LOCK_FILE );
		if ( null === $lock ) {
			throw new JobException( sprintf( 'Job %s is being run by another process.', $job->id ) );
		}

		// Another process may have advanced the job between loading it and taking the lock
		// (a retried web request, for example): continue from the saved state, not a stale copy.
		$fresh = $this->store->load( $job->id );
		foreach ( get_object_vars( $fresh ) as $property => $value ) {
			$job->$property = $value;
		}
		if ( $job->is_finished() ) {
			$lock->release();
			return $job;
		}

		$this->stop_requested = false;
		$restore_signals      = $handle_signals ? $this->install_signal_handlers() : false;

		try {
			$this->loop( $job, $deadline, $lock, $dir );
		} finally {
			if ( $restore_signals ) {
				$this->restore_signal_handlers();
			}
			$lock->release();
		}

		return $job;
	}

	/**
	 * Main loop.
	 *
	 * @param Job      $job      Job.
	 * @param Deadline $deadline Time budget.
	 * @param Lock     $lock     Held lock.
	 * @param string   $dir      Job folder.
	 * @return void
	 */
	private function loop( Job $job, Deadline $deadline, Lock $lock, string $dir ): void {
		$steps  = $this->registry->steps( $job->type );
		$logger = function ( string $message ) use ( $job ): void {
			$this->store->log( $job->id, $message );
		};

		if ( Job::STATUS_RUNNING !== $job->status ) {
			$logger( Job::STATUS_PENDING === $job->status ? 'Job started.' : sprintf( 'Resumed at step %d/%d (%s).', $job->step + 1, count( $steps ), $job->status ) );
		}
		$job->status      = Job::STATUS_RUNNING;
		$job->error       = null;
		$job->finished_at = 0;
		$this->store->save( $job );

		$interrupted = function () use ( $job ): bool {
			return $this->stop_requested || $this->store->cancel_requested( $job->id );
		};

		$total = count( $steps );
		while ( $job->step < $total ) {
			if ( $this->store->cancel_requested( $job->id ) ) {
				$this->cancel( $job, $logger );
				return;
			}
			if ( $this->stop_requested ) {
				$job->status = Job::STATUS_PAUSED;
				$logger( 'Paused by signal.' );
				$this->checkpoint( $job );
				return;
			}

			$step       = $steps[ $job->step ];
			$job->phase = $step->label();
			$context    = new Context(
				$dir,
				$deadline,
				$this->slice_seconds,
				$interrupted,
				$logger,
				function () use ( $job ): void {
					$this->notify( $job );
				}
			);

			try {
				$done = $step->run( $job, $context );
			} catch ( \Throwable $e ) {
				$job->status      = Job::STATUS_FAILED;
				$job->error       = $e->getMessage();
				$job->finished_at = time();
				$logger( sprintf( 'Failed in %s: %s', $step->label(), $e->getMessage() ) );
				$this->checkpoint( $job );
				return;
			}

			if ( $done ) {
				$logger( sprintf( 'Step %d/%d done: %s.', $job->step + 1, $total, $step->label() ) );
				++$job->step;
				$job->cursor = array();
			}

			$lock->heartbeat();
			$this->checkpoint( $job );

			if ( $job->step < $total && $deadline->expired() ) {
				return; // Status stays "running"; the next request continues.
			}
		}

		$job->status      = Job::STATUS_COMPLETED;
		$job->phase       = '';
		$job->finished_at = time();
		Secrets::forget( $job ); // Passwords are not kept once the job is done.
		if ( $job->bytes_total > 0 ) {
			$job->bytes_done = $job->bytes_total;
		}
		$logger( 'Job completed.' );
		$this->checkpoint( $job );
	}

	/**
	 * Marks the job cancelled and deletes its work files.
	 *
	 * @param Job                    $job    Job.
	 * @param callable(string): void $logger Job log writer.
	 * @return void
	 */
	private function cancel( Job $job, callable $logger ): void {
		$logger( 'Cancelled.' );
		$this->apply_cancel( $job );
	}

	/**
	 * Marks a job cancelled, removes its secrets and work files, then lets its
	 * steps clean up. The secrets are gone from disk before any cleanup starts
	 * (a slow or hanging cleanup cannot leave them behind); the steps get an
	 * in-memory copy that still holds them (a pull needs its key to delete
	 * the backup it made on the source site).
	 *
	 * @param Job $job Job (the caller holds its lock).
	 * @return void
	 */
	public function apply_cancel( Job $job ): void {
		$job->status      = Job::STATUS_CANCELLED;
		$job->finished_at = time();
		$copy             = clone $job;
		Secrets::forget( $job ); // A cancelled job never needs its passwords again.
		$this->store->save( $job );
		$this->store->purge_work_files( $job->id );
		$this->discard( $copy );
		$this->notify( $job );
	}

	/**
	 * Lets the job's steps delete what they wrote outside the job folder (for example a partial archive).
	 *
	 * @param Job $job Cancelled job.
	 * @return void
	 */
	public function discard( Job $job ): void {
		foreach ( $this->registry->steps( $job->type ) as $step ) {
			if ( $step instanceof Discardable ) {
				$step->discard( $job );
			}
		}
	}

	/**
	 * Saves the job and reports progress.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function checkpoint( Job $job ): void {
		$this->store->save( $job );
		$this->notify( $job );
	}

	/**
	 * Calls the progress callback.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function notify( Job $job ): void {
		if ( null !== $this->on_checkpoint ) {
			( $this->on_checkpoint )( $job );
		}
	}

	/**
	 * Turns SIGINT / SIGTERM into a graceful pause.
	 *
	 * @return bool Whether handlers were installed.
	 */
	private function install_signal_handlers(): bool {
		if ( ! function_exists( 'pcntl_signal' ) || ! function_exists( 'pcntl_async_signals' ) || ! defined( 'SIGINT' ) || ! defined( 'SIGTERM' ) ) {
			return false;
		}
		pcntl_async_signals( true );
		$handler = function (): void {
			$this->stop_requested = true;
		};
		pcntl_signal( SIGINT, $handler );
		pcntl_signal( SIGTERM, $handler );
		return true;
	}

	/**
	 * Restores default signal handling.
	 *
	 * @return void
	 */
	private function restore_signal_handlers(): void {
		pcntl_signal( SIGINT, SIG_DFL );
		pcntl_signal( SIGTERM, SIG_DFL );
	}
}
