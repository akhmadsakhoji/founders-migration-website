<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Controller;

defined( 'ABSPATH' ) || exit;

use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\JobToken;
use Founders\Migration\Remote\Storages;
use Founders\Migration\Schedule\ScheduleOptions;
use Founders\Migration\Schedule\Scheduler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API fmw/v1/schedules: list, create, change, delete and run backup schedules.
 */
final class ScheduleRestController {

	const SCHEDULE_ID = '(?P<id>[a-f0-9]{8})';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the routes (all need the plugin capability).
	 *
	 * @return void
	 */
	public function routes(): void {
		$admin = static function (): bool {
			return current_user_can( fmwp_capability() );
		};
		register_rest_route(
			RestController::NAMESPACE_V1,
			'/schedules',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			RestController::NAMESPACE_V1,
			'/schedules/' . self::SCHEDULE_ID,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			RestController::NAMESPACE_V1,
			'/schedules/' . self::SCHEDULE_ID . '/run',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run' ),
					'permission_callback' => $admin,
				),
			)
		);
	}

	/**
	 * GET /schedules.
	 *
	 * @return WP_REST_Response
	 */
	public function index(): WP_REST_Response {
		$items = array();
		foreach ( Scheduler::store()->all() as $schedule ) {
			$items[] = self::view( $schedule );
		}
		$health = Scheduler::health();
		return new WP_REST_Response(
			array(
				'schedules' => $items,
				'health'    => $health + array( 'last_tick_text' => self::when( $health['last_tick'] ) ),
				'timezone'  => wp_timezone_string(),
			)
		);
	}

	/**
	 * POST /schedules.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		return $this->save( $request, null );
	}

	/**
	 * POST /schedules/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$existing = Scheduler::store()->get( (string) $request['id'] );
		if ( null === $existing ) {
			return self::not_found();
		}
		return $this->save( $request, $existing );
	}

	/**
	 * DELETE /schedules/<id>. Its backups stay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		if ( ! Scheduler::store()->delete( (string) $request['id'] ) ) {
			return self::not_found();
		}
		Scheduler::sync_event();
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * POST /schedules/<id>/run: starts the backup now; the browser then runs it like an export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( WP_REST_Request $request ) {
		$schedule = Scheduler::store()->get( (string) $request['id'] );
		if ( null === $schedule ) {
			return self::not_found();
		}
		try {
			$job = Scheduler::run_now( $schedule, 'run now' );
		} catch ( JobException $e ) {
			return new WP_Error( 'fmw_busy', $e->getMessage(), array( 'status' => 409 ) );
		}
		$store = Jobs::store();
		return new WP_REST_Response( RestController::summary( $job, $store ) + array( 'token' => JobToken::issue( $job, $store ) ), 201 );
	}

	/**
	 * Creates or changes a schedule from the request body.
	 *
	 * @param WP_REST_Request          $request  Request.
	 * @param array<string,mixed>|null $existing Schedule being changed.
	 * @return WP_REST_Response|WP_Error
	 */
	private function save( WP_REST_Request $request, ?array $existing ) {
		$input = array();
		foreach ( array( 'name', 'enabled', 'frequency', 'time', 'weekday', 'monthday', 'flags', 'keep', 'notify', 'email', 'storage', 'remote_keep', 'keep_local' ) as $field ) {
			if ( null !== $request[ $field ] ) {
				$input[ $field ] = $request[ $field ];
			}
		}
		if ( is_string( $request['password'] ) ) {
			$input['password'] = $request['password']; // Raw: a password must not be "sanitized"; '' removes it.
		}
		if ( isset( $input['name'] ) ) {
			$input['name'] = sanitize_text_field( (string) $input['name'] );
		}
		if ( ! empty( $input['storage'] ) && null === Storages::get( (string) $input['storage'] ) ) {
			return new WP_Error( 'fmw_invalid_schedule', __( 'That cloud storage does not exist any more.', 'founders-migration-website' ), array( 'status' => 400 ) );
		}
		try {
			$schedule = ScheduleOptions::build( $input, $existing, time(), wp_timezone() );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_schedule', $e->getMessage(), array( 'status' => 400 ) );
		}
		if ( null !== $existing ) {
			// Keep what the scheduler wrote meanwhile (last run, backups), not the copy read before.
			$schedule = Scheduler::store()->change(
				(string) $existing['id'],
				static function ( array $current ) use ( $schedule ): array {
					$schedule['state'] = array_merge( (array) $current['state'], array( 'next_run' => $schedule['state']['next_run'] ) );
					return $schedule;
				}
			);
			if ( null === $schedule ) {
				return self::not_found();
			}
		} else {
			Scheduler::store()->save( $schedule );
		}
		Scheduler::sync_event();
		return new WP_REST_Response( self::view( $schedule ), null === $existing ? 201 : 200 );
	}

	/**
	 * A schedule as the browser shows it.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @return array<string,mixed>
	 */
	public static function view( array $schedule ): array {
		$view                  = ScheduleOptions::public_view( $schedule );
		$view['description']   = Scheduler::describe( $schedule );
		$view['next_run_text'] = self::when( (int) ( $schedule['state']['next_run'] ?? 0 ) );
		$view['last_run_text'] = self::when( (int) ( $schedule['state']['last_run'] ?? 0 ) );
		return $view;
	}

	/**
	 * A time in the site's format, or an empty string.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function when( int $time ): string {
		return $time > 0 ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
	}

	/**
	 * The "unknown schedule" error.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'fmw_not_found', __( 'Schedule not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
	}
}
