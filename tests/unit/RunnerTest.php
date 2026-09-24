<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests\Unit;

use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Lock;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\StepRegistry;
use Founders\Migration\Tests\Fixtures\BusyStep;
use Founders\Migration\Tests\Fixtures\CountingStep;
use Founders\Migration\Tests\Fixtures\FailOnceStep;
use Founders\Migration\Tests\Fixtures\StopStep;
use Founders\Migration\Tests\TestCase;

final class RunnerTest extends TestCase {

	/**
	 * Store.
	 *
	 * @var JobStore
	 */
	private $store;

	/**
	 * Registry.
	 *
	 * @var StepRegistry
	 */
	private $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->store    = new JobStore( $this->tmp . '/jobs' );
		$this->registry = new StepRegistry();
		$this->registry->register( 'count', array( CountingStep::class, CountingStep::class ) );
		$this->registry->register( 'flaky', array( FailOnceStep::class, CountingStep::class ) );
		$this->registry->register( 'stop', array( StopStep::class, CountingStep::class ) );
		$this->registry->register( 'busy', array( BusyStep::class ) );
		CountingStep::$calls = 0;
		FailOnceStep::$fail  = true;
		StopStep::$runner    = null;
	}

	/**
	 * Runs a job the way a fresh process would: reload from disk first.
	 *
	 * @param string   $id       Job ID.
	 * @param Deadline $deadline Budget.
	 * @param float    $slice    Slice seconds.
	 * @return Job
	 */
	private function run_fresh( string $id, Deadline $deadline, float $slice = 10.0 ): Job {
		$runner = new Runner( $this->store, $this->registry, null, $slice );
		return $runner->run( $this->store->load( $id ), $deadline );
	}

	public function test_multi_step_job_completes_and_releases_its_lock(): void {
		$job = $this->store->create( 'count' );
		$checkpoints = 0;
		$runner      = new Runner(
			$this->store,
			$this->registry,
			static function () use ( &$checkpoints ) {
				++$checkpoints;
			}
		);

		$runner->run( $job, Deadline::unlimited() );

		$this->assertSame( Job::STATUS_COMPLETED, $job->status );
		$this->assertSame( 2, $job->step );
		$this->assertSame( 10, CountingStep::$calls );
		$this->assertSame( 11, $checkpoints, 'one checkpoint per slice, plus completion' );
		$this->assertSame( Job::STATUS_COMPLETED, $this->store->load( $job->id )->status );
		$this->assertFalse( file_exists( $this->store->dir( $job->id ) . '/lock' ) );
		$this->assertContains( 'Job completed.', array_map( static function ( $line ) {
			return substr( $line, 21 );
		}, $this->store->log_lines( $job->id ) ) );
	}

	public function test_expired_deadline_yields_and_a_new_process_continues(): void {
		$job = $this->store->create( 'count' );

		$runs = 0;
		do {
			$job = $this->run_fresh( $job->id, new Deadline( 0.0 ) );
			++$runs;
		} while ( Job::STATUS_RUNNING === $job->status && $runs < 50 );

		$this->assertSame( Job::STATUS_COMPLETED, $job->status );
		$this->assertSame( 10, $runs, 'each web request does exactly one slice when the budget is spent' );
		$this->assertSame( 10, CountingStep::$calls );
	}

	public function test_slices_end_on_time_and_are_checkpointed(): void {
		$job = $this->store->create( 'busy' );

		$started = microtime( true );
		$job     = $this->run_fresh( $job->id, Deadline::unlimited(), 0.05 );

		$this->assertSame( Job::STATUS_COMPLETED, $job->status );
		$this->assertLessThan( 1.0, microtime( true ) - $started );
	}

	public function test_failure_is_recorded_and_resume_retries(): void {
		$job = $this->run_fresh( $this->store->create( 'flaky' )->id, Deadline::unlimited() );

		$this->assertSame( Job::STATUS_FAILED, $job->status );
		$this->assertSame( 'Disk full while writing part-0001.tar.gz', $job->error );
		$this->assertGreaterThan( 0, $job->finished_at );
		$this->assertFalse( $job->is_finished(), 'failed jobs stay resumable' );

		$job = $this->run_fresh( $job->id, Deadline::unlimited() );

		$this->assertSame( Job::STATUS_COMPLETED, $job->status );
		$this->assertNull( $job->error );
	}

	public function test_signal_pauses_at_the_next_checkpoint(): void {
		$job              = $this->store->create( 'stop' );
		$runner           = new Runner( $this->store, $this->registry );
		StopStep::$runner = $runner;

		$runner->run( $job, Deadline::unlimited() );

		$this->assertSame( Job::STATUS_PAUSED, $job->status );
		$this->assertSame( 1, $job->step, 'the interrupted step finished its slice and was recorded' );
		$this->assertSame( 0, CountingStep::$calls );

		StopStep::$runner = null;
		$job              = $this->run_fresh( $job->id, Deadline::unlimited() );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status );
	}

	public function test_cancel_request_stops_and_removes_work_files(): void {
		$job = $this->store->create( 'count' );
		$dir = $this->store->dir( $job->id );
		file_put_contents( $dir . '/part-0001.tar.gz', 'partial' );
		$this->store->request_cancel( $job->id );

		$job = $this->run_fresh( $job->id, Deadline::unlimited() );

		$this->assertSame( Job::STATUS_CANCELLED, $job->status );
		$this->assertFileDoesNotExist( $dir . '/part-0001.tar.gz' );
		$this->assertFileDoesNotExist( $dir . '/cancel' );
		$this->assertFileExists( $dir . '/state.json' );
		$this->assertFileExists( $dir . '/job.log' );
		$this->assertSame( 0, CountingStep::$calls );
	}

	public function test_a_job_locked_by_another_process_is_refused(): void {
		$job  = $this->store->create( 'count' );
		$lock = Lock::acquire( $this->store->dir( $job->id ) . '/lock' );

		try {
			$this->run_fresh( $job->id, Deadline::unlimited() );
			$this->fail( 'Expected JobException.' );
		} catch ( JobException $e ) {
			$this->assertSame( 0, CountingStep::$calls );
		} finally {
			$lock->release();
		}
	}

	public function test_finished_jobs_cannot_run_again(): void {
		$job = $this->run_fresh( $this->store->create( 'count' )->id, Deadline::unlimited() );

		$this->expectException( JobException::class );
		$this->run_fresh( $job->id, Deadline::unlimited() );
	}

	public function test_unknown_type_fails_without_leaving_a_lock(): void {
		$job = $this->store->create( 'does-not-exist' );

		try {
			$this->run_fresh( $job->id, Deadline::unlimited() );
			$this->fail( 'Expected JobException.' );
		} catch ( JobException $e ) {
			$this->assertFalse( file_exists( $this->store->dir( $job->id ) . '/lock' ) );
		}
	}

	public function test_registry_refuses_classes_that_are_not_steps(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->registry->register( 'evil', array( \ArrayObject::class ) );
	}
}
