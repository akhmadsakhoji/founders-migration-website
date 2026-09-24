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

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * State of one backup, restore or pull job, persisted as state.json.
 *
 * Everything a step needs to continue after a crash lives here: the current
 * step index, the step's own cursor, and data shared between steps.
 */
final class Job {

	const STATE_VERSION = 1;

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * ULID.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Job type, for example "backup". Selects the steps from the StepRegistry.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * One of the STATUS_* constants.
	 *
	 * @var string
	 */
	public $status = self::STATUS_PENDING;

	/**
	 * Options the job was started with (flags from the CLI or the UI).
	 *
	 * @var array<string,mixed>
	 */
	public $options = array();

	/**
	 * Index of the current step.
	 *
	 * @var int
	 */
	public $step = 0;

	/**
	 * Position inside the current step; reset when a step completes.
	 *
	 * @var array<string,mixed>
	 */
	public $cursor = array();

	/**
	 * Results shared between steps (part list, totals, ...).
	 *
	 * @var array<string,mixed>
	 */
	public $data = array();

	/**
	 * Label of the running step, for progress output.
	 *
	 * @var string
	 */
	public $phase = '';

	/**
	 * Bytes processed so far.
	 *
	 * @var int
	 */
	public $bytes_done = 0;

	/**
	 * Bytes expected in total, 0 while unknown.
	 *
	 * @var int
	 */
	public $bytes_total = 0;

	/**
	 * Last error message of a failed job.
	 *
	 * @var string|null
	 */
	public $error = null;

	/**
	 * Unix time of creation.
	 *
	 * @var int
	 */
	public $created_at = 0;

	/**
	 * Unix time of the last checkpoint.
	 *
	 * @var int
	 */
	public $updated_at = 0;

	/**
	 * Unix time the job completed, failed or was cancelled; 0 otherwise.
	 *
	 * @var int
	 */
	public $finished_at = 0;

	/**
	 * Constructor.
	 *
	 * @param string              $id      ULID.
	 * @param string              $type    Job type.
	 * @param array<string,mixed> $options Options.
	 */
	public function __construct( string $id, string $type, array $options = array() ) {
		$this->id         = $id;
		$this->type       = $type;
		$this->options    = $options;
		$this->created_at = time();
		$this->updated_at = $this->created_at;
	}

	/**
	 * Whether the job can never run again.
	 *
	 * @return bool
	 */
	public function is_finished(): bool {
		return in_array( $this->status, array( self::STATUS_COMPLETED, self::STATUS_CANCELLED ), true );
	}

	/**
	 * Progress as a fraction 0..1, or null while the total is unknown.
	 *
	 * @return float|null
	 */
	public function progress(): ?float {
		if ( self::STATUS_COMPLETED === $this->status ) {
			return 1.0;
		}
		return $this->bytes_total > 0 ? min( 1.0, $this->bytes_done / $this->bytes_total ) : null;
	}

	/**
	 * Serialisable form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'state_version' => self::STATE_VERSION,
			'id'            => $this->id,
			'type'          => $this->type,
			'status'        => $this->status,
			'options'       => $this->options,
			'step'          => $this->step,
			'cursor'        => $this->cursor,
			'data'          => $this->data,
			'phase'         => $this->phase,
			'bytes_done'    => $this->bytes_done,
			'bytes_total'   => $this->bytes_total,
			'error'         => $this->error,
			'created_at'    => $this->created_at,
			'updated_at'    => $this->updated_at,
			'finished_at'   => $this->finished_at,
		);
	}

	/**
	 * Rebuilds a job from to_array() output.
	 *
	 * @param array<string,mixed> $state Decoded state.json.
	 * @return self
	 * @throws JobException On an unknown state version or missing fields.
	 */
	public static function from_array( array $state ): self {
		if ( ! isset( $state['state_version'], $state['id'], $state['type'], $state['status'] ) || self::STATE_VERSION !== $state['state_version'] ) {
			throw new JobException( 'Unsupported or incomplete job state.' );
		}

		$job              = new self( (string) $state['id'], (string) $state['type'], (array) ( $state['options'] ?? array() ) );
		$job->status      = (string) $state['status'];
		$job->step        = (int) ( $state['step'] ?? 0 );
		$job->cursor      = (array) ( $state['cursor'] ?? array() );
		$job->data        = (array) ( $state['data'] ?? array() );
		$job->phase       = (string) ( $state['phase'] ?? '' );
		$job->bytes_done  = (int) ( $state['bytes_done'] ?? 0 );
		$job->bytes_total = (int) ( $state['bytes_total'] ?? 0 );
		$job->error       = isset( $state['error'] ) ? (string) $state['error'] : null;
		$job->created_at  = (int) ( $state['created_at'] ?? 0 );
		$job->updated_at  = (int) ( $state['updated_at'] ?? 0 );
		$job->finished_at = (int) ( $state['finished_at'] ?? 0 );

		return $job;
	}
}
