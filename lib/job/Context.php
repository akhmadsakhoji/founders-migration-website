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

/**
 * What a step gets from the Runner for one slice of work.
 */
final class Context {

	/**
	 * Default length of one slice between checkpoints, in seconds.
	 */
	const SLICE_SECONDS = 10.0;

	/**
	 * Job working folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Overall time budget of this run.
	 *
	 * @var Deadline
	 */
	private $deadline;

	/**
	 * End of the current slice (microtime).
	 *
	 * @var float
	 */
	private $slice_end;

	/**
	 * Returns true when the run must stop (signal or cancel request).
	 *
	 * @var callable(): bool
	 */
	private $interrupted;

	/**
	 * Writes a line to the job log.
	 *
	 * @var callable(string): void
	 */
	private $logger;

	/**
	 * Reports progress without saving the job.
	 *
	 * @var callable(): void
	 */
	private $reporter;

	/**
	 * Whether should_continue() has been called in this slice yet.
	 *
	 * @var bool
	 */
	private $started = false;

	/**
	 * Constructor.
	 *
	 * @param string                 $dir           Job working folder.
	 * @param Deadline               $deadline      Overall time budget.
	 * @param float                  $slice_seconds Length of this slice.
	 * @param callable(): bool       $interrupted   Stop / cancel check.
	 * @param callable(string): void $logger        Job log writer.
	 * @param callable(): void|null  $reporter      Progress reporter.
	 */
	public function __construct( string $dir, Deadline $deadline, float $slice_seconds, callable $interrupted, callable $logger, ?callable $reporter = null ) {
		$this->dir         = $dir;
		$this->deadline    = $deadline;
		$this->slice_end   = microtime( true ) + $slice_seconds;
		$this->interrupted = $interrupted;
		$this->logger      = $logger;
		$this->reporter    = null === $reporter ? static function (): void {} : $reporter;
	}

	/**
	 * Shows the job's current bytes_done / bytes_total to the user.
	 *
	 * Steps call this as often as they like (the progress bar throttles
	 * output); checkpoints only happen between slices.
	 *
	 * @return void
	 */
	public function report_progress(): void {
		( $this->reporter )();
	}

	/**
	 * Whether the step may keep working in this slice.
	 *
	 * The first call of a slice always allows one unit of work (unless the job
	 * is being stopped or cancelled), so a job keeps moving even when every
	 * web request arrives with its time budget nearly spent.
	 *
	 * @return bool
	 */
	public function should_continue(): bool {
		if ( ( $this->interrupted )() ) {
			return false;
		}
		if ( ! $this->started ) {
			$this->started = true;
			return true;
		}
		return microtime( true ) < $this->slice_end && ! $this->deadline->expired();
	}

	/**
	 * Seconds left in this slice (for sizing the next unit of work, such as an upload part).
	 *
	 * @return float
	 */
	public function remaining(): float {
		$left     = max( 0.0, $this->slice_end - microtime( true ) );
		$deadline = $this->deadline->remaining();
		return null === $deadline ? $left : min( $left, $deadline );
	}

	/**
	 * Job working folder, for temporary parts and lists.
	 *
	 * @return string
	 */
	public function dir(): string {
		return $this->dir;
	}

	/**
	 * Writes to the job log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function log( string $message ): void {
		( $this->logger )( $message );
	}
}
