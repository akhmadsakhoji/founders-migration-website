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

use Founders\Migration\Job\Secrets;
use Founders\Migration\Model\Export\BackupOptions;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Checks and builds schedules from user input (CLI flags, REST body).
 *
 * A schedule: id, name, enabled, frequency, time (HH:MM), weekday (0 =
 * Sunday), monthday (1-28), flags (backup exclusions), secret_password
 * (sealed, optional), keep (newest backups of this schedule to keep, 0 =
 * all), notify (failure, always, never), email ('' = the site's admin
 * e-mail), storage (cloud storage ID to upload to, '' for none),
 * remote_keep (newest uploads to keep there, 0 = all), keep_local (keep the
 * copy on this server after the upload), created_at, and state (next_run,
 * last_run, last_job, last_status, last_error, backups and remote_backups:
 * file names and object keys this schedule created, oldest first).
 */
final class ScheduleOptions {

	const NOTIFY   = array( 'failure', 'always', 'never' );
	const MAX_KEEP = 1000;

	/**
	 * Backup flags a schedule can carry.
	 *
	 * @return string[]
	 */
	public static function flag_names(): array {
		return array_merge( array_keys( BackupOptions::EXCLUDE_FLAGS ), array( 'exclude-database' ) );
	}

	/**
	 * A new or changed schedule.
	 *
	 * @param array<string,mixed>      $input    Fields to set; a missing field keeps its current (or default) value.
	 *                                           password: a new password, '' to remove it.
	 * @param array<string,mixed>|null $existing Schedule being changed, or null for a new one.
	 * @param int                      $now      Unix time.
	 * @param \DateTimeZone            $zone     Site time zone.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On an invalid value.
	 */
	public static function build( array $input, ?array $existing, int $now, \DateTimeZone $zone ): array {
		$schedule  = $existing ?? array(
			'id'              => bin2hex( random_bytes( 4 ) ),
			'name'            => '',
			'enabled'         => true,
			'frequency'       => 'daily',
			'time'            => '02:00',
			'weekday'         => 0,
			'monthday'        => 1,
			'flags'           => array(),
			'secret_password' => null,
			'keep'            => 7,
			'notify'          => 'failure',
			'email'           => '',
			'storage'         => '',
			'remote_keep'     => 30,
			'keep_local'      => true,
			'created_at'      => $now,
			'state'           => array(
				'next_run'    => 0,
				'last_run'    => 0,
				'last_job'    => '',
				'last_status' => '',
				'last_error'  => '',
				'backups'     => array(),
			),
		);
		$schedule += array(
			'storage'     => '',
			'remote_keep' => 30,
			'keep_local'  => true,
		); // Schedules saved before cloud storage existed.

		if ( array_key_exists( 'name', $input ) ) {
			$schedule['name'] = self::clean( (string) $input['name'], 100 );
		}
		if ( array_key_exists( 'enabled', $input ) ) {
			$schedule['enabled'] = self::bool( $input['enabled'] );
		}
		if ( array_key_exists( 'frequency', $input ) ) {
			$frequency = strtolower( (string) $input['frequency'] );
			if ( ! in_array( $frequency, Recurrence::FREQUENCIES, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Frequency must be one of: %s.', implode( ', ', Recurrence::FREQUENCIES ) ) );
			}
			$schedule['frequency'] = $frequency;
		}
		if ( array_key_exists( 'time', $input ) ) {
			$time = trim( (string) $input['time'], " \t\n\r\0\x0B" );
			if ( 1 !== preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $time ) ) {
				throw new \InvalidArgumentException( 'Time must be HH:MM (24-hour clock, site time zone).' );
			}
			list( $hour, $minute ) = Recurrence::time( $time );
			$schedule['time']      = sprintf( '%02d:%02d', $hour, $minute );
		}
		if ( array_key_exists( 'weekday', $input ) ) {
			$schedule['weekday'] = self::weekday( $input['weekday'] );
		}
		if ( array_key_exists( 'monthday', $input ) ) {
			$day = self::integer( $input['monthday'], 'Day of the month' );
			if ( $day < 1 || $day > 28 ) {
				throw new \InvalidArgumentException( 'Day of the month must be 1 to 28 (every month has it).' );
			}
			$schedule['monthday'] = $day;
		}
		if ( array_key_exists( 'flags', $input ) ) {
			$flags = array();
			foreach ( (array) $input['flags'] as $flag => $value ) {
				if ( ! in_array( (string) $flag, self::flag_names(), true ) ) {
					throw new \InvalidArgumentException( sprintf( 'Unknown backup option: %s.', $flag ) );
				}
				if ( self::bool( $value ) ) {
					$flags[ (string) $flag ] = true;
				}
			}
			ksort( $flags );
			$schedule['flags'] = $flags;
		}
		if ( array_key_exists( 'password', $input ) ) {
			$password = is_string( $input['password'] ) ? $input['password'] : '';
			if ( '' === $password ) {
				$schedule['secret_password'] = null;
			} elseif ( strlen( $password ) < BackupOptions::MIN_PASSWORD ) {
				throw new \InvalidArgumentException( sprintf( 'The backup password must be at least %d characters long.', BackupOptions::MIN_PASSWORD ) );
			} else {
				$schedule['secret_password'] = Secrets::seal( $password );
			}
		}
		if ( array_key_exists( 'keep', $input ) ) {
			$keep = self::integer( $input['keep'], 'Keep' );
			if ( $keep < 0 || $keep > self::MAX_KEEP ) {
				throw new \InvalidArgumentException( sprintf( 'Keep must be 0 (all) to %d backups.', self::MAX_KEEP ) );
			}
			$schedule['keep'] = $keep;
		}
		if ( array_key_exists( 'notify', $input ) ) {
			$notify = strtolower( (string) $input['notify'] );
			if ( ! in_array( $notify, self::NOTIFY, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Notify must be one of: %s.', implode( ', ', self::NOTIFY ) ) );
			}
			$schedule['notify'] = $notify;
		}
		if ( array_key_exists( 'email', $input ) ) {
			$email = trim( (string) $input['email'], " \t\n\r\0\x0B" );
			if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				throw new \InvalidArgumentException( sprintf( '"%s" is not a valid e-mail address.', $email ) );
			}
			$schedule['email'] = $email;
		}

		if ( array_key_exists( 'storage', $input ) ) {
			$storage = strtolower( trim( (string) $input['storage'], " \t\n\r\0\x0B" ) );
			if ( '' !== $storage && 1 !== preg_match( '/^[a-f0-9]{8}$/', $storage ) ) {
				throw new \InvalidArgumentException( 'Unknown cloud storage; see `wp fmw storage list`.' );
			}
			$schedule['storage'] = $storage;
		}
		if ( array_key_exists( 'remote_keep', $input ) ) {
			$keep = self::integer( $input['remote_keep'], 'Keep in the cloud' );
			if ( $keep < 0 || $keep > self::MAX_KEEP ) {
				throw new \InvalidArgumentException( sprintf( 'Keep in the cloud must be 0 (all) to %d backups.', self::MAX_KEEP ) );
			}
			$schedule['remote_keep'] = $keep;
		}
		if ( array_key_exists( 'keep_local', $input ) ) {
			$schedule['keep_local'] = self::bool( $input['keep_local'] );
		}
		if ( '' === $schedule['storage'] ) {
			$schedule['keep_local'] = true; // Without an upload, the copy here is the only one.
		}

		if ( '' === $schedule['name'] ) {
			$schedule['name'] = ucfirst( (string) $schedule['frequency'] ) . ' backup';
		}
		$schedule['state']['next_run'] = $schedule['enabled'] ? Recurrence::next( $schedule, $now, $zone ) : 0;
		return $schedule;
	}

	/**
	 * Schedule without its sealed password, for display and the REST API.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @return array<string,mixed>
	 */
	public static function public_view( array $schedule ): array {
		$schedule['encrypted'] = ! empty( $schedule['secret_password'] );
		unset( $schedule['secret_password'] );
		return $schedule;
	}

	/**
	 * A day of the week as 0 (Sunday) to 6, from a number or an English name.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \InvalidArgumentException When it is not a day.
	 */
	public static function weekday( $value ): int {
		$names = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );
		$text  = strtolower( substr( trim( (string) $value, " \t\n\r\0\x0B" ), 0, 3 ) );
		$index = array_search( $text, $names, true );
		if ( false !== $index ) {
			return (int) $index;
		}
		$day = self::integer( $value, 'Weekday' );
		if ( 7 === $day ) {
			return 0;
		}
		if ( $day < 0 || $day > 6 ) {
			throw new \InvalidArgumentException( 'Weekday must be 0 (Sunday) to 6, or a name like "monday".' );
		}
		return $day;
	}

	/**
	 * A whole number.
	 *
	 * @param mixed  $value Value.
	 * @param string $label Field name for the error.
	 * @return int
	 * @throws \InvalidArgumentException When it is not one.
	 */
	private static function integer( $value, string $label ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,6}$/', trim( $value, " \t\n\r\0\x0B" ) ) ) {
			return (int) $value;
		}
		throw new \InvalidArgumentException( sprintf( '%s must be a whole number.', $label ) );
	}

	/**
	 * A yes/no value from JSON or a flag.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function bool( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Single-line text without control characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function clean( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ), " \t\n\r\0\x0B" );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
