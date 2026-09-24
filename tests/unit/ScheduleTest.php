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

use Founders\Migration\Job\Secrets;
use Founders\Migration\Schedule\Recurrence;
use Founders\Migration\Schedule\ScheduleOptions;
use Founders\Migration\Schedule\ScheduleStore;
use Founders\Migration\Tests\TestCase;

/**
 * Schedules: when they run, how they are validated and stored.
 */
final class ScheduleTest extends TestCase {

	/**
	 * Next run as local "Y-m-d H:i D".
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @param string              $after    Local time.
	 * @param string              $zone     Time zone.
	 * @return string
	 */
	private function next( array $schedule, string $after, string $zone = 'Asia/Jakarta' ): string {
		$tz   = new \DateTimeZone( $zone );
		$from = ( new \DateTimeImmutable( $after, $tz ) )->getTimestamp();
		$next = Recurrence::next( $schedule, $from, $tz );
		$this->assertGreaterThan( $from, $next );
		return ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( $tz )->format( 'Y-m-d H:i D' );
	}

	public function test_next_run_for_each_frequency(): void {
		$daily = array( 'frequency' => 'daily', 'time' => '02:00' );
		$this->assertSame( '2026-09-25 02:00 Fri', $this->next( $daily, '2026-09-25 01:00' ) );
		$this->assertSame( '2026-09-26 02:00 Sat', $this->next( $daily, '2026-09-25 02:00' ), 'Strictly after: not the minute it just ran.' );
		$this->assertSame( '2027-01-01 02:00 Fri', $this->next( $daily, '2026-12-31 23:59' ) );

		$hourly = array( 'frequency' => 'hourly', 'time' => '09:15' );
		$this->assertSame( '2026-09-25 10:15 Fri', $this->next( $hourly, '2026-09-25 10:10' ) );
		$this->assertSame( '2026-09-25 11:15 Fri', $this->next( $hourly, '2026-09-25 10:20' ) );
		$this->assertSame( '2026-09-26 00:15 Sat', $this->next( $hourly, '2026-09-25 23:30' ) );

		$weekly = array( 'frequency' => 'weekly', 'time' => '03:30', 'weekday' => 0 );
		$this->assertSame( '2026-09-27 03:30 Sun', $this->next( $weekly, '2026-09-25 12:00' ) );
		$this->assertSame( '2026-09-27 03:30 Sun', $this->next( $weekly, '2026-09-27 03:00' ) );
		$this->assertSame( '2026-10-04 03:30 Sun', $this->next( $weekly, '2026-09-27 04:00' ) );

		$monthly = array( 'frequency' => 'monthly', 'time' => '01:00', 'monthday' => 28 );
		$this->assertSame( '2026-09-28 01:00 Mon', $this->next( $monthly, '2026-09-25 00:00' ) );
		$this->assertSame( '2026-10-28 01:00 Wed', $this->next( $monthly, '2026-09-28 02:00' ) );
		$this->assertSame( '2027-01-28 01:00 Thu', $this->next( $monthly, '2026-12-29 00:00' ), 'December rolls over into January.' );
		$this->assertSame( '2026-02-28 01:00 Sat', $this->next( $monthly, '2026-01-31 00:00' ) );
	}

	public function test_daylight_saving_days_neither_skip_nor_repeat(): void {
		$daily = array( 'frequency' => 'daily', 'time' => '02:30' );
		// 2026-03-29 02:30 does not exist in Berlin (clocks jump from 02:00 to 03:00).
		$this->assertSame( '2026-03-29 03:30 Sun', $this->next( $daily, '2026-03-29 00:00', 'Europe/Berlin' ) );
		$this->assertSame( '2026-03-30 02:30 Mon', $this->next( $daily, '2026-03-29 03:31', 'Europe/Berlin' ) );
		// 2026-10-25 02:30 happens twice; the run happens once.
		$first = $this->next( $daily, '2026-10-25 00:00', 'Europe/Berlin' );
		$this->assertSame( '2026-10-25 02:30 Sun', $first );
		$tz    = new \DateTimeZone( 'Europe/Berlin' );
		$after = ( new \DateTimeImmutable( '2026-10-25 02:30', $tz ) )->getTimestamp();
		$this->assertSame( '2026-10-26 02:30', ( new \DateTimeImmutable( '@' . Recurrence::next( $daily, $after, $tz ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i' ) );
	}

	public function test_building_a_schedule_validates_and_fills_defaults(): void {
		$zone     = new \DateTimeZone( 'UTC' );
		$now      = ( new \DateTimeImmutable( '2026-09-25 12:00', $zone ) )->getTimestamp();
		$schedule = ScheduleOptions::build( array( 'flags' => array( 'exclude-cache' => true, 'exclude-media' => 'false' ) ), null, $now, $zone );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}$/', $schedule['id'] );
		$this->assertSame( 'Daily backup', $schedule['name'] );
		$this->assertSame( '02:00', $schedule['time'] );
		$this->assertSame( 7, $schedule['keep'] );
		$this->assertSame( array( 'exclude-cache' => true ), $schedule['flags'] );
		$this->assertSame( ( new \DateTimeImmutable( '2026-09-26 02:00', $zone ) )->getTimestamp(), $schedule['state']['next_run'] );

		$changed = ScheduleOptions::build(
			array(
				'name'      => "Weekly\n<b>full</b>",
				'frequency' => 'WEEKLY',
				'weekday'   => 'Monday',
				'time'      => '7:05',
				'keep'      => '0',
				'email'     => 'ops@example.com',
			),
			$schedule,
			$now,
			$zone
		);
		$this->assertSame( $schedule['id'], $changed['id'] );
		$this->assertSame( 'Weekly <b>full</b>', $changed['name'] );
		$this->assertSame( 1, $changed['weekday'] );
		$this->assertSame( '07:05', $changed['time'] );
		$this->assertSame( 0, $changed['keep'] );
		$this->assertSame( array( 'exclude-cache' => true ), $changed['flags'], 'Fields not given keep their value.' );

		$off = ScheduleOptions::build( array( 'enabled' => false ), $changed, $now, $zone );
		$this->assertSame( 0, $off['state']['next_run'] );
		$this->assertSame( 0, ScheduleOptions::weekday( '7' ) );

		$bad = array(
			array( 'frequency' => 'yearly' ),
			array( 'time' => '24:00' ),
			array( 'time' => '2am' ),
			array( 'monthday' => 31 ),
			array( 'keep' => -1 ),
			array( 'keep' => '1e3' ),
			array( 'weekday' => 'someday' ),
			array( 'notify' => 'sometimes' ),
			array( 'email' => 'not-an-address' ),
			array( 'flags' => array( 'exclude-everything' => true ) ),
			array( 'password' => 'short' ),
		);
		foreach ( $bad as $input ) {
			try {
				ScheduleOptions::build( $input, null, $now, $zone );
				$this->fail( 'Accepted ' . json_encode( $input ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_the_password_is_sealed_kept_and_removable(): void {
		$zone     = new \DateTimeZone( 'UTC' );
		$schedule = ScheduleOptions::build( array( 'password' => 'Rahasia 2026' ), null, 1790000000, $zone );
		$this->assertSame( 'Rahasia 2026', Secrets::open( $schedule['secret_password'] ) );
		$this->assertStringNotContainsString( 'Rahasia', (string) json_encode( $schedule ) );

		$view = ScheduleOptions::public_view( $schedule );
		$this->assertTrue( $view['encrypted'] );
		$this->assertArrayNotHasKey( 'secret_password', $view );

		$kept = ScheduleOptions::build( array( 'keep' => 3 ), $schedule, 1790000000, $zone );
		$this->assertSame( $schedule['secret_password'], $kept['secret_password'] );
		$removed = ScheduleOptions::build( array( 'password' => '' ), $kept, 1790000000, $zone );
		$this->assertNull( $removed['secret_password'] );
		$this->assertFalse( ScheduleOptions::public_view( $removed )['encrypted'] );
	}

	public function test_the_store_keeps_schedules_and_refuses_to_overwrite_a_damaged_file(): void {
		$file  = $this->tmp . '/schedules.json';
		$store = new ScheduleStore( $file );
		$this->assertSame( array(), $store->all() );

		$store->save( array( 'id' => 'aaaa1111', 'name' => 'A', 'state' => array( 'backups' => array() ) ) );
		$store->save( array( 'id' => 'bbbb2222', 'name' => 'B', 'state' => array( 'backups' => array() ) ) );
		$store->set_meta( 'last_tick', 123 );
		$this->assertSame( array( 'aaaa1111', 'bbbb2222' ), array_keys( $store->all() ) );
		$this->assertSame( 123, $store->meta()['last_tick'] );

		$changed = $store->change(
			'aaaa1111',
			static function ( array $schedule ): array {
				$schedule['state']['backups'][] = 'x.fmw';
				return $schedule;
			}
		);
		$this->assertSame( array( 'x.fmw' ), $changed['state']['backups'] );
		$this->assertSame( array( 'x.fmw' ), ( new ScheduleStore( $file ) )->get( 'aaaa1111' )['state']['backups'] );
		$this->assertNull( $store->change( 'missing0', 'strval' ) );
		$this->assertTrue( $store->delete( 'bbbb2222' ) );
		$this->assertFalse( $store->delete( 'bbbb2222' ) );
		$this->assertSame( array(), glob( $this->tmp . '/*.tmp' ) );

		file_put_contents( $file, '{"schedules": {"aaaa1111": ' ); // Damaged (for example by hand).
		$this->assertSame( array(), $store->all() );
		try {
			$store->set_meta( 'last_tick', 124 );
			$this->fail( 'A damaged file must not be overwritten.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'damaged', $e->getMessage() );
		}
		$this->assertSame( '{"schedules": {"aaaa1111": ', file_get_contents( $file ) );
	}
}
