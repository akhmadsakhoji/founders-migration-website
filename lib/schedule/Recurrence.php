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

defined( 'ABSPATH' ) || exit;

/**
 * When a schedule runs next, in the site's time zone.
 *
 * Hourly runs at minute MM of every hour; daily at HH:MM; weekly on a
 * weekday at HH:MM; monthly on day 1-28 at HH:MM (28 so that every month
 * has the day). Runs that were missed while the site was down are not
 * caught up: the next run is always the first one after "now".
 */
final class Recurrence {

	const FREQUENCIES = array( 'hourly', 'daily', 'weekly', 'monthly' );

	/**
	 * First run strictly after $after.
	 *
	 * @param array<string,mixed> $schedule Schedule (frequency, time, weekday, monthday).
	 * @param int                 $after    Unix time.
	 * @param \DateTimeZone       $zone     Site time zone.
	 * @return int Unix time.
	 */
	public static function next( array $schedule, int $after, \DateTimeZone $zone ): int {
		list( $hour, $minute ) = self::time( (string) ( $schedule['time'] ?? '00:00' ) );
		$now                   = ( new \DateTimeImmutable( '@' . $after ) )->setTimezone( $zone );

		switch ( (string) ( $schedule['frequency'] ?? 'daily' ) ) {
			case 'hourly':
				$next = $now->setTime( (int) $now->format( 'G' ), $minute );
				if ( $next->getTimestamp() <= $after ) {
					$next = $next->modify( '+1 hour' );
					$next = $next->setTime( (int) $next->format( 'G' ), $minute );
				}
				break;

			case 'weekly':
				$weekday = max( 0, min( 6, (int) ( $schedule['weekday'] ?? 1 ) ) );
				$days    = ( $weekday - (int) $now->format( 'w' ) + 7 ) % 7;
				$next    = $now->modify( '+' . $days . ' days' )->setTime( $hour, $minute );
				if ( $next->getTimestamp() <= $after ) {
					$next = $next->modify( '+7 days' )->setTime( $hour, $minute );
				}
				break;

			case 'monthly':
				$day  = max( 1, min( 28, (int) ( $schedule['monthday'] ?? 1 ) ) );
				$next = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), $day )->setTime( $hour, $minute );
				if ( $next->getTimestamp() <= $after ) {
					$next = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ) + 1, $day )->setTime( $hour, $minute );
				}
				break;

			default: // Daily.
				$next = $now->setTime( $hour, $minute );
				if ( $next->getTimestamp() <= $after ) {
					$next = $next->modify( '+1 day' )->setTime( $hour, $minute );
				}
		}

		// A wall-clock time that does not exist on a daylight saving day can land before $after.
		$time = $next->getTimestamp();
		return $time > $after ? $time : $after + 60;
	}

	/**
	 * Hours and minutes of "HH:MM".
	 *
	 * @param string $time Time.
	 * @return array{0:int,1:int}
	 */
	public static function time( string $time ): array {
		if ( 1 !== preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $match ) ) {
			return array( 0, 0 );
		}
		return array( (int) $match[1], (int) $match[2] );
	}
}
