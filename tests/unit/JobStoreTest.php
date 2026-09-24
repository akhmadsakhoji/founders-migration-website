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

use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\Lock;
use Founders\Migration\Job\Ulid;
use Founders\Migration\Tests\TestCase;

final class JobStoreTest extends TestCase {

	public function test_state_round_trips_through_disk(): void {
		$store = new JobStore( $this->tmp . '/jobs' );
		$job   = $store->create( 'backup', array( 'exclude-cache' => true ) );

		$job->step        = 2;
		$job->cursor      = array( 'table' => 'wp_posts', 'last_id' => 250000 );
		$job->data        = array( 'parts' => array( 'files/part-0001.tar.gz' ) );
		$job->bytes_done  = 51200000000;
		$job->bytes_total = 91500000000;
		$job->phase       = 'Files';
		$store->save( $job );

		$loaded = $store->load( $job->id );
		$this->assertSame( $job->to_array(), $loaded->to_array() );
		$this->assertSame( array(), glob( $store->dir( $job->id ) . '/*.tmp' ), 'no temporary files left behind' );
		$this->assertEqualsWithDelta( 0.5595, $loaded->progress(), 0.0001 );
	}

	public function test_lowercase_ids_are_accepted(): void {
		$store = new JobStore( $this->tmp . '/jobs' );
		$job   = $store->create( 'backup' );

		$this->assertSame( $job->id, $store->load( strtolower( $job->id ) )->id );
	}

	/**
	 * @dataProvider bad_ids
	 *
	 * @param string $id Malformed ID.
	 */
	public function test_malformed_ids_never_become_paths( string $id ): void {
		$this->expectException( JobException::class );
		( new JobStore( $this->tmp . '/jobs' ) )->load( $id );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function bad_ids(): array {
		return array(
			'traversal' => array( '../../wp-config' ),
			'short'     => array( 'ABC' ),
			'bad chars' => array( '01J8Z3K9QXAB12CD34EF56GH7U' ),
			'empty'     => array( '' ),
		);
	}

	public function test_state_copied_from_another_job_is_rejected(): void {
		$store = new JobStore( $this->tmp . '/jobs' );
		$a     = $store->create( 'backup' );
		$b     = $store->create( 'backup' );
		copy( $store->dir( $a->id ) . '/state.json', $store->dir( $b->id ) . '/state.json' );

		$this->expectException( JobException::class );
		$store->load( $b->id );
	}

	public function test_all_lists_newest_first_and_skips_junk(): void {
		$store = new JobStore( $this->tmp . '/jobs' );
		$first = $store->create( 'backup' );
		usleep( 2000 );
		$second = $store->create( 'restore' );
		mkdir( $this->tmp . '/jobs/not-a-job' );
		mkdir( $this->tmp . '/jobs/' . Ulid::generate() ); // Folder without state.

		$ids = array_map(
			static function ( Job $job ) {
				return $job->id;
			},
			$store->all()
		);
		$this->assertSame( array( $second->id, $first->id ), $ids );
	}

	public function test_log_keeps_one_line_per_message(): void {
		$store = new JobStore( $this->tmp . '/jobs' );
		$job   = $store->create( 'backup' );
		foreach ( range( 1, 5 ) as $i ) {
			$store->log( $job->id, "line $i\nwith newline" );
		}

		$lines = $store->log_lines( $job->id, 2 );
		$this->assertCount( 2, $lines );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ line 5 with newline$/', $lines[1] );
	}

	public function test_ulids_are_valid_and_sort_by_time(): void {
		$early = Ulid::generate( 1700000000000 );
		$late  = Ulid::generate( 1700000000001 );

		$this->assertTrue( Ulid::is_valid( $early ) );
		$this->assertSame( 26, strlen( $early ) );
		$this->assertLessThan( 0, strcmp( $early, $late ) );
		$this->assertNotSame( Ulid::generate( 1 ), Ulid::generate( 1 ) );
	}

	public function test_lock_is_exclusive_until_released(): void {
		$path = $this->tmp . '/lock';
		$a    = Lock::acquire( $path );

		$this->assertNotNull( $a );
		$this->assertNull( Lock::acquire( $path ) );
		$this->assertTrue( Lock::is_held( $path ) );

		$a->release();
		$this->assertFalse( Lock::is_held( $path ) );
		$b = Lock::acquire( $path );
		$this->assertNotNull( $b );
		$b->release();
	}

	public function test_a_holder_without_heartbeat_is_taken_over(): void {
		$path = $this->tmp . '/lock';
		$hung = Lock::acquire( $path );
		file_put_contents( $path, json_encode( array( 'pid' => 1, 'host' => 'x', 'heartbeat' => time() - 600 ) ) );

		$this->assertFalse( Lock::is_held( $path ) );
		$new = Lock::acquire( $path );
		$this->assertNotNull( $new );

		// The hung process waking up and releasing must not delete the new holder's lock.
		$hung->release();
		$this->assertTrue( Lock::is_held( $path ) );
		$this->assertNull( Lock::acquire( $path ) );
		$new->release();
	}
}
