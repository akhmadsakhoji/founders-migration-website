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

use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\Runner;
use Founders\Migration\Remote\Storages;
use Founders\Migration\Schedule\ScheduleOptions;
use Founders\Migration\Schedule\Scheduler;
use WP_CLI;

/**
 * Manages scheduled backups.
 *
 * Schedules run through WP-Cron (checked every five minutes, then continued
 * in the background) or, more reliably for large sites, from a system cron:
 *
 *     0-59/5 * * * * wp fmw schedule run --path=/var/www/example.com --quiet
 *
 * ## EXAMPLES
 *
 *     wp fmw schedule add --frequency=daily --time=02:00 --keep=7
 *     wp fmw schedule add --name="Weekly full" --frequency=weekly --weekday=sunday --time=03:30 --keep=4 --notify=always
 *     wp fmw schedule list
 *     wp fmw schedule run
 */
final class ScheduleCommand {

	/**
	 * Lists schedules.
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
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$format = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$rows   = array();
		foreach ( Scheduler::store()->all() as $schedule ) {
			$state  = (array) $schedule['state'];
			$rows[] = array(
				'id'          => $schedule['id'],
				'name'        => $schedule['name'],
				'enabled'     => $schedule['enabled'] ? 'yes' : 'no',
				'when'        => Scheduler::describe( $schedule ),
				'keep'        => $schedule['keep'] ? (string) $schedule['keep'] : 'all',
				'exclude'     => implode( ',', array_map( array( self::class, 'short_flag' ), array_keys( (array) $schedule['flags'] ) ) ),
				'encrypted'   => empty( $schedule['secret_password'] ) ? 'no' : 'yes',
				'notify'      => $schedule['notify'] . ( '' !== $schedule['email'] ? ' (' . $schedule['email'] . ')' : '' ),
				'next_run'    => self::when( (int) ( $state['next_run'] ?? 0 ) ),
				'last_run'    => self::when( (int) ( $state['last_run'] ?? 0 ) ),
				'last_status' => (string) ( $state['last_status'] ?? '' ) . ( '' !== (string) ( $state['last_error'] ?? '' ) ? ': ' . $state['last_error'] : '' ),
				'backups'     => count( (array) ( $state['backups'] ?? array() ) ),
				'upload'      => self::upload_text( $schedule ),
			);
		}
		if ( ! $rows && 'table' === $format ) {
			WP_CLI::log( 'No schedules yet. Add one with `wp fmw schedule add --frequency=daily --time=02:00`.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'name', 'enabled', 'when', 'keep', 'upload', 'exclude', 'encrypted', 'notify', 'next_run', 'last_run', 'last_status', 'backups' ) );

		if ( 'table' === $format ) {
			$health = Scheduler::health();
			WP_CLI::log( sprintf( 'Times are in the site time zone (%s). Last scheduler check: %s%s.', wp_timezone_string(), $health['last_tick'] ? self::when( $health['last_tick'] ) : 'never', '' !== $health['source'] ? ' via ' . $health['source'] : '' ) );
			if ( '' !== $health['waiting_for'] ) {
				WP_CLI::warning( sprintf( 'Scheduled backups wait: job %s (a restore or reset) is unfinished. Resume it with `wp fmw resume %1$s` or cancel it with `wp fmw cancel %1$s`.', $health['waiting_for'] ) );
			}
			if ( $health['late'] ) {
				WP_CLI::warning( 'The scheduler has not run for over 30 minutes' . ( $health['wp_cron_disabled'] ? ' and DISABLE_WP_CRON is set' : '' ) . '. Add a system cron: 0-59/5 * * * * wp fmw schedule run --path=' . untrailingslashit( ABSPATH ) . ' --quiet' );
			}
		}
	}

	/**
	 * Adds a schedule.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Name shown in lists and e-mails.
	 *
	 * [--frequency=<frequency>]
	 * : How often.
	 * ---
	 * default: daily
	 * options:
	 *   - hourly
	 *   - daily
	 *   - weekly
	 *   - monthly
	 * ---
	 *
	 * [--time=<time>]
	 * : Time of day in the site time zone (hourly: only the minutes count).
	 * ---
	 * default: 02:00
	 * ---
	 *
	 * [--weekday=<day>]
	 * : Weekly: day of the week (sunday..saturday or 0..6).
	 *
	 * [--monthday=<day>]
	 * : Monthly: day of the month, 1 to 28.
	 *
	 * [--keep=<count>]
	 * : Newest backups of this schedule to keep; older ones are deleted. 0 keeps all.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--notify=<when>]
	 * : When to send an e-mail.
	 * ---
	 * default: failure
	 * options:
	 *   - failure
	 *   - always
	 *   - never
	 * ---
	 *
	 * [--email=<address>]
	 * : Where to send it (default: the site's admin e-mail).
	 *
	 * [--password[=<password>]]
	 * : Encrypt the backups (at least 8 characters). Without a value it is asked for, twice, without echo.
	 *
	 * [--storage=<id>]
	 * : Upload each backup to this cloud storage (see `wp fmw storage list`); "" for none.
	 *
	 * [--remote-keep=<count>]
	 * : Newest uploads of this schedule to keep in the storage; older ones are deleted there. 0 keeps all.
	 *
	 * [--[no-]keep-local]
	 * : Keep the copy on this server after the upload (default: yes).
	 *
	 * [--disabled]
	 * : Save the schedule without running it yet.
	 *
	 * [--exclude-spam-comments]
	 * : Leave out spam comments.
	 *
	 * [--exclude-post-revisions]
	 * : Leave out post revisions.
	 *
	 * [--exclude-transients]
	 * : Leave out transients.
	 *
	 * [--exclude-media]
	 * : Leave out uploads.
	 *
	 * [--exclude-themes]
	 * : Leave out all themes.
	 *
	 * [--exclude-inactive-themes]
	 * : Leave out inactive themes.
	 *
	 * [--exclude-muplugins]
	 * : Leave out must-use plugins.
	 *
	 * [--exclude-plugins]
	 * : Leave out all plugins.
	 *
	 * [--exclude-inactive-plugins]
	 * : Leave out inactive plugins.
	 *
	 * [--exclude-cache]
	 * : Leave out cache folders.
	 *
	 * [--exclude-database]
	 * : Leave out the database.
	 *
	 * [--porcelain]
	 * : Print only the new schedule's ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw schedule add --frequency=daily --time=02:00 --keep=14
	 *     wp fmw schedule add --frequency=monthly --monthday=1 --time=01:00 --keep=12 --password
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function add( $args, $assoc_args ) {
		$input            = $this->input( $assoc_args, true );
		$input['enabled'] = ! WP_CLI\Utils\get_flag_value( $assoc_args, 'disabled', false );
		foreach ( array(
			'frequency' => 'daily',
			'time'      => '02:00',
			'keep'      => '7',
			'notify'    => 'failure',
		) as $field => $default ) {
			$input[ $field ] = $input[ $field ] ?? $default;
		}
		$schedule = $this->build( $input, null );
		Scheduler::store()->save( $schedule );
		Scheduler::sync_event();
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $schedule['id'] );
			return;
		}
		WP_CLI::success( sprintf( 'Schedule %s added: %s, next run %s.', $schedule['id'], Scheduler::describe( $schedule ), $schedule['enabled'] ? self::when( (int) $schedule['state']['next_run'] ) : '(disabled)' ) );
	}

	/**
	 * Changes a schedule. Only the options given change.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * [--name=<name>]
	 * : Name.
	 *
	 * [--frequency=<frequency>]
	 * : hourly, daily, weekly or monthly.
	 *
	 * [--time=<time>]
	 * : Time of day.
	 *
	 * [--weekday=<day>]
	 * : Weekly: day of the week.
	 *
	 * [--monthday=<day>]
	 * : Monthly: day of the month, 1 to 28.
	 *
	 * [--keep=<count>]
	 * : Backups to keep, 0 = all.
	 *
	 * [--notify=<when>]
	 * : failure, always or never.
	 *
	 * [--email=<address>]
	 * : E-mail address ("" for the admin e-mail).
	 *
	 * [--password[=<password>]]
	 * : New password for the backups.
	 *
	 * [--no-password]
	 * : Stop encrypting the backups.
	 *
	 * [--storage=<id>]
	 * : Upload each backup to this cloud storage (see `wp fmw storage list`); "" for none.
	 *
	 * [--remote-keep=<count>]
	 * : Newest uploads of this schedule to keep in the storage; older ones are deleted there. 0 keeps all.
	 *
	 * [--[no-]keep-local]
	 * : Keep the copy on this server after the upload (default: yes).
	 *
	 * [--exclude=<flags>]
	 * : Replace the exclusions, comma-separated (media,cache,...); "" for none.
	 *
	 * @param string[]                  $args       Positional arguments.
	 * @param array<string,string|bool> $assoc_args Flags.
	 * @return void
	 */
	public function update( $args, $assoc_args ) {
		$existing = $this->get( $args[0] );
		$input    = $this->input( $assoc_args, false );
		if ( isset( $assoc_args['exclude'] ) ) {
			$input['flags'] = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['exclude'] ) ) ) as $name ) {
				$flag                    = 0 === strpos( $name, 'exclude-' ) ? $name : 'exclude-' . $name;
				$input['flags'][ $flag ] = true;
			}
		}
		if ( array_key_exists( 'password', $assoc_args ) && false === $assoc_args['password'] ) {
			$input['password'] = ''; // --no-password.
		}
		$schedule = $this->build( $input, $existing );
		$schedule = $this->store_change( $schedule );
		WP_CLI::success( sprintf( 'Schedule %s updated: %s%s.', $schedule['id'], Scheduler::describe( $schedule ), $schedule['enabled'] ? ', next run ' . self::when( (int) $schedule['state']['next_run'] ) : ' (disabled)' ) );
	}

	/**
	 * Deletes a schedule. The backups it made stay.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		$this->get( $args[0] );
		Scheduler::store()->delete( $args[0] );
		Scheduler::sync_event();
		WP_CLI::success( sprintf( 'Schedule %s deleted (its backups were kept).', $args[0] ) );
	}

	/**
	 * Enables a schedule.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function enable( $args, $assoc_args ) {
		$schedule = $this->store_change( $this->build( array( 'enabled' => true ), $this->get( $args[0] ) ) );
		WP_CLI::success( sprintf( 'Schedule %s enabled, next run %s.', $schedule['id'], self::when( (int) $schedule['state']['next_run'] ) ) );
	}

	/**
	 * Disables a schedule.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule ID.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function disable( $args, $assoc_args ) {
		$schedule = $this->store_change( $this->build( array( 'enabled' => false ), $this->get( $args[0] ) ) );
		WP_CLI::success( sprintf( 'Schedule %s disabled.', $schedule['id'] ) );
	}

	/**
	 * Runs due schedules in the foreground (for a system cron), or one schedule now.
	 *
	 * Without an ID: records finished scheduled backups, continues one that
	 * stopped, then runs every schedule that is due, one after the other.
	 * Prints nothing when nothing is due with --quiet. Exit codes: 0 done or
	 * nothing to do, 1 a backup failed, 3 stopped (resumable).
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Run this schedule now, due or not.
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default when not --quiet).
	 *
	 * ## EXAMPLES
	 *
	 *     # crontab -e (every five minutes)
	 *     0-59/5 * * * * wp fmw schedule run --path=/var/www/example.com --quiet
	 *
	 *     wp fmw schedule run 1a2b3c4d
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		$progress = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', ! WP_CLI::get_config( 'quiet' ) );
		if ( isset( $args[0] ) ) {
			$schedule = $this->get( $args[0] );
			try {
				$job = Scheduler::run_now( $schedule, 'cli' );
			} catch ( JobException $e ) {
				WP_CLI::error( $e->getMessage() );
				return;
			}
		} else {
			$job = Scheduler::tick( time(), 'cli' );
		}

		$failed = false;
		while ( null !== $job ) {
			WP_CLI::log( sprintf( 'Schedule "%s" · job %s', (string) ( $job->options['schedule_name'] ?? '' ), $job->id ) );
			$bar    = $progress ? new ProgressBar() : null;
			$runner = new Runner(
				Jobs::store(),
				Jobs::registry(),
				static function ( Job $job ) use ( $bar ) {
					if ( null !== $bar && '' !== $job->phase ) {
						$bar->update( $job->phase, $job->bytes_done, $job->bytes_total );
					}
				}
			);
			try {
				$job = $runner->run( $job, Deadline::unlimited(), true );
			} catch ( JobException $e ) {
				WP_CLI::warning( $e->getMessage() );
				return; // Another process runs it.
			} finally {
				if ( null !== $bar ) {
					$bar->finish();
				}
			}

			if ( Job::STATUS_COMPLETED === $job->status ) {
				$upload = isset( $job->data['remote']['key'] ) ? sprintf( ', uploaded to "%s"%s', $job->data['remote']['name'], empty( $job->data['archive']['deleted_local'] ) ? '' : ' (not kept on this server)' ) : '';
				WP_CLI::success( sprintf( 'Backup created: %s (%s)%s.', (string) ( $job->data['archive']['name'] ?? '' ), ProgressBar::bytes( (int) ( $job->data['archive']['bytes'] ?? 0 ) ), $upload ) );
			} elseif ( Job::STATUS_FAILED === $job->status ) {
				WP_CLI::warning( sprintf( 'Backup failed: %s', (string) $job->error ) );
				$failed = true;
			} elseif ( ! $job->is_finished() ) {
				WP_CLI::warning( sprintf( 'Job %s stopped; the scheduler continues it on its next run.', $job->id ) );
				WP_CLI::halt( Command::EXIT_RESUMABLE );
			}
			if ( isset( $args[0] ) ) {
				Scheduler::record( $job ); // "Run now": only this one.
				$job = null;
			} else {
				$job = Scheduler::after( $job, false ); // The next due schedule, if any.
			}
		}
		if ( $failed ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Schedule by ID, or an error.
	 *
	 * @param string $id ID.
	 * @return array<string,mixed>
	 */
	private function get( string $id ): array {
		$schedule = Scheduler::store()->get( $id );
		if ( null === $schedule ) {
			WP_CLI::error( sprintf( 'Schedule "%s" not found; see `wp fmw schedule list`.', $id ) );
		}
		return (array) $schedule;
	}

	/**
	 * Input fields from flags.
	 *
	 * @param array<string,mixed> $assoc_args Flags.
	 * @param bool                $with_flags Read the --exclude-* flags.
	 * @return array<string,mixed>
	 */
	private function input( array $assoc_args, bool $with_flags ): array {
		$input = array();
		foreach ( array( 'name', 'frequency', 'time', 'weekday', 'monthday', 'keep', 'notify', 'email', 'storage', 'remote-keep' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) && ! is_bool( $assoc_args[ $flag ] ) ) {
				$input[ str_replace( '-', '_', $flag ) ] = (string) $assoc_args[ $flag ];
			}
		}
		if ( array_key_exists( 'keep-local', $assoc_args ) ) {
			$input['keep_local'] = (bool) $assoc_args['keep-local'];
		}
		if ( ! empty( $input['storage'] ) && null === Storages::get( (string) $input['storage'] ) ) {
			WP_CLI::error( sprintf( 'Cloud storage "%s" not found; see `wp fmw storage list`.', $input['storage'] ) );
		}
		if ( $with_flags ) {
			$input['flags'] = array();
			foreach ( ScheduleOptions::flag_names() as $flag ) {
				if ( ! empty( $assoc_args[ $flag ] ) ) {
					$input['flags'][ $flag ] = true;
				}
			}
		}
		if ( isset( $assoc_args['password'] ) && false !== $assoc_args['password'] ) {
			$input['password'] = $this->password( $assoc_args['password'] );
		}
		return $input;
	}

	/**
	 * Builds the schedule, or stops with the validation error.
	 *
	 * @param array<string,mixed>      $input    Fields.
	 * @param array<string,mixed>|null $existing Schedule being changed.
	 * @return array<string,mixed>
	 */
	private function build( array $input, ?array $existing ): array {
		try {
			return ScheduleOptions::build( $input, $existing, time(), wp_timezone() );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		exit( 1 ); // Not reached.
	}

	/**
	 * Saves a changed schedule, keeping the run state the scheduler wrote meanwhile.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @return array<string,mixed>
	 */
	private function store_change( array $schedule ): array {
		$saved = Scheduler::store()->change(
			(string) $schedule['id'],
			static function ( array $current ) use ( $schedule ): array {
				$schedule['state'] = array_merge( (array) $current['state'], array( 'next_run' => $schedule['state']['next_run'] ) );
				return $schedule;
			}
		);
		Scheduler::sync_event();
		return null === $saved ? $schedule : $saved;
	}

	/**
	 * The password from --password=<value>, or asked for twice without echo.
	 *
	 * @param mixed $value Flag value (true when given without a value).
	 * @return string
	 */
	private function password( $value ): string {
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
		if ( ! function_exists( 'posix_isatty' ) || ! posix_isatty( STDIN ) ) {
			WP_CLI::error( 'Pass the password as --password=<password> (no terminal to ask it on).' );
		}
		$password = (string) \cli\prompt( 'Password for the backups of this schedule', false, ': ', true );
		if ( (string) \cli\prompt( 'Repeat the password', false, ': ', true ) !== $password ) {
			WP_CLI::error( 'The passwords do not match.' );
		}
		return $password;
	}

	/**
	 * Local date and time.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function when( int $time ): string {
		return $time > 0 ? (string) wp_date( 'Y-m-d H:i', $time ) : '';
	}

	/**
	 * Where a schedule uploads to, for the list.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @return string
	 */
	private static function upload_text( array $schedule ): string {
		$id = (string) ( $schedule['storage'] ?? '' );
		if ( '' === $id ) {
			return '';
		}
		$storage = Storages::get( $id );
		return ( null === $storage ? $id . ' (missing!)' : (string) $storage['name'] ) . ', keep ' . ( ! empty( $schedule['remote_keep'] ) ? (int) $schedule['remote_keep'] : 'all' ) . ( empty( $schedule['keep_local'] ) ? ', not here' : '' );
	}

	/**
	 * "exclude-media" as "media".
	 *
	 * @param string $flag Flag.
	 * @return string
	 */
	private static function short_flag( string $flag ): string {
		return 0 === strpos( $flag, 'exclude-' ) ? substr( $flag, 8 ) : $flag;
	}
}
