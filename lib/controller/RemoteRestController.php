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

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\JobToken;
use Founders\Migration\Remote\GoogleAuth;
use Founders\Migration\Remote\RemoteException;
use Founders\Migration\Remote\StorageOptions;
use Founders\Migration\Remote\Storages;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API for cloud storages: fmw/v1/storages and /backups/<name>/upload. All routes need the plugin capability.
 */
final class RemoteRestController {

	const STORAGE_ID = '(?P<id>[a-f0-9]{8})';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function routes(): void {
		$admin  = static function (): bool {
			return current_user_can( fmwp_capability() );
		};
		$routes = array(
			'/storages'                                   => array(
				'GET'  => 'index',
				'POST' => 'create',
			),
			'/storages/' . self::STORAGE_ID               => array(
				'POST'   => 'update',
				'DELETE' => 'delete',
			),
			'/storages/' . self::STORAGE_ID . '/test'     => array( 'POST' => 'test' ),
			'/storages/' . self::STORAGE_ID . '/connect'  => array( 'POST' => 'connect' ),
			'/storages/' . self::STORAGE_ID . '/disconnect' => array( 'POST' => 'disconnect' ),
			'/storages/' . self::STORAGE_ID . '/files'    => array( 'GET' => 'files' ),
			'/storages/' . self::STORAGE_ID . '/files/delete' => array( 'POST' => 'remove_file' ),
			'/storages/' . self::STORAGE_ID . '/download' => array( 'POST' => 'download' ),
			'/backups/(?P<name>[^/]+)/upload'             => array( 'POST' => 'upload' ),
		);
		foreach ( $routes as $route => $methods ) {
			$handlers = array();
			foreach ( $methods as $method => $callback ) {
				$handlers[] = array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $admin,
				);
			}
			register_rest_route( RestController::NAMESPACE_V1, $route, $handlers );
		}
	}

	/**
	 * GET /storages: storages (without secrets) and provider presets.
	 *
	 * @return WP_REST_Response
	 */
	public function index(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'storages'  => array_values( array_map( array( StorageOptions::class, 'public_view' ), Storages::store()->all() ) ),
				'providers' => StorageOptions::providers(),
				'redirect'  => GoogleAuth::redirect_uri(),
				'curl'      => function_exists( 'curl_init' ),
			)
		);
	}

	/**
	 * POST /storages: adds a storage after the connection test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		return $this->save( $request, null );
	}

	/**
	 * POST /storages/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$existing = Storages::get( (string) $request['id'] );
		return null === $existing ? self::not_found() : $this->save( $request, $existing );
	}

	/**
	 * DELETE /storages/<id>: removes it from this site; its files stay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		return Storages::store()->delete( (string) $request['id'] ) ? new WP_REST_Response( array( 'deleted' => true ) ) : self::not_found();
	}

	/**
	 * POST /storages/<id>/test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ) {
		$storage = Storages::get( (string) $request['id'] );
		if ( null === $storage ) {
			return self::not_found();
		}
		try {
			return new WP_REST_Response( array( 'result' => Storages::test( $storage ) ) );
		} catch ( RemoteException $e ) {
			return self::remote_error( $e );
		}
	}

	/**
	 * POST /storages/<id>/connect: Google's consent page for this user (the browser goes there).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect( WP_REST_Request $request ) {
		$storage = Storages::get( (string) $request['id'] );
		if ( null === $storage || 'gdrive' !== $storage['provider'] ) {
			return self::not_found();
		}
		try {
			return new WP_REST_Response( array( 'url' => GoogleAuth::authorize_url( $storage, get_current_user_id() ) ) );
		} catch ( RemoteException $e ) {
			return self::remote_error( $e );
		}
	}

	/**
	 * POST /storages/<id>/disconnect: forgets the Google sign-in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function disconnect( WP_REST_Request $request ) {
		$storage = Storages::get( (string) $request['id'] );
		if ( null === $storage || 'gdrive' !== $storage['provider'] ) {
			return self::not_found();
		}
		GoogleAuth::disconnect( $storage );
		return new WP_REST_Response( array( 'disconnected' => true ) );
	}

	/**
	 * GET /storages/<id>/files: backups in the storage folder, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function files( WP_REST_Request $request ) {
		$storage = Storages::get( (string) $request['id'] );
		if ( null === $storage ) {
			return self::not_found();
		}
		try {
			$items = Storages::backups( $storage );
		} catch ( RemoteException $e ) {
			return self::remote_error( $e );
		}
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		foreach ( $items as &$item ) {
			$item['date'] = (string) wp_date( $format, $item['mtime'] );
			$item['here'] = null !== Backups::get( $item['name'] );
		}
		return new WP_REST_Response( array( 'files' => $items ) );
	}

	/**
	 * POST /storages/<id>/files/delete {name}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_file( WP_REST_Request $request ) {
		$storage = Storages::get( (string) $request['id'] );
		$name    = (string) $request['name'];
		if ( null === $storage ) {
			return self::not_found();
		}
		if ( '' === $name || basename( $name ) !== $name ) {
			return new WP_Error( 'fmw_invalid_name', __( 'Unknown backup.', 'founders-migration-website' ), array( 'status' => 400 ) );
		}
		try {
			Storages::delete_backup( $storage, $name );
		} catch ( RemoteException $e ) {
			return self::remote_error( $e );
		}
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * POST /storages/<id>/download {name}: a job that brings the backup into the backups folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function download( WP_REST_Request $request ) {
		$storage = Storages::get( (string) $request['id'] );
		if ( null === $storage ) {
			return self::not_found();
		}
		if ( null !== RestController::active_job( '' ) ) {
			return RestController::busy();
		}
		Paths::ensure_all();
		try {
			$options = Storages::download_job( $storage, (string) $request['name'] );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_name', $e->getMessage(), array( 'status' => 400 ) );
		}
		return self::start( 'download', $options );
	}

	/**
	 * POST /backups/<name>/upload {storage}: a job that uploads a backup of the backups folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$storage = Storages::get( (string) $request['storage'] );
		$backup  = Backups::get( (string) $request['name'] );
		if ( null === $storage || null === $backup ) {
			return self::not_found();
		}
		if ( null !== RestController::active_job( '' ) ) {
			return RestController::busy();
		}
		return self::start( 'upload', Storages::upload_job( $storage, (string) $backup['path'] ) );
	}

	/**
	 * Creates or changes a storage from the request body, after testing it.
	 *
	 * @param WP_REST_Request          $request  Request.
	 * @param array<string,mixed>|null $existing Storage being changed.
	 * @return WP_REST_Response|WP_Error
	 */
	private function save( WP_REST_Request $request, ?array $existing ) {
		$input = array();
		foreach ( array( 'name', 'provider', 'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'path_style', 'storage_class', 'client_id' ) as $field ) {
			if ( null !== $request[ $field ] ) {
				$input[ $field ] = is_bool( $request[ $field ] ) ? $request[ $field ] : sanitize_text_field( (string) $request[ $field ] );
			}
		}
		foreach ( array( 'secret_key', 'client_secret' ) as $field ) {
			if ( is_string( $request[ $field ] ) && '' !== $request[ $field ] ) {
				$input[ $field ] = $request[ $field ]; // Raw: a secret must not be "sanitized".
			}
		}
		try {
			$storage = StorageOptions::build( $input, $existing, time() );
			if ( 'gdrive' !== $storage['provider'] || ! empty( $storage['refresh'] ) ) {
				Storages::test( $storage ); // A new Google Drive storage is tested once connected.
			}
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_storage', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( RemoteException $e ) {
			return self::remote_error( $e );
		}
		$storage = Storages::save_settings( $storage );
		return new WP_REST_Response( StorageOptions::public_view( $storage ), null === $existing ? 201 : 200 );
	}

	/**
	 * Creates a job and its token for the browser.
	 *
	 * @param string              $type    Job type.
	 * @param array<string,mixed> $options Options.
	 * @return WP_REST_Response
	 */
	private static function start( string $type, array $options ): WP_REST_Response {
		$store = Jobs::store();
		$job   = $store->create( $type, $options );
		return new WP_REST_Response( RestController::summary( $job, $store ) + array( 'token' => JobToken::issue( $job, $store ) ), 201 );
	}

	/**
	 * A storage error for the browser.
	 *
	 * @param RemoteException $e Error.
	 * @return WP_Error
	 */
	private static function remote_error( RemoteException $e ): WP_Error {
		return new WP_Error( 'fmw_storage_error', $e->getMessage(), array( 'status' => 502 ) );
	}

	/**
	 * The "unknown storage" error.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'fmw_not_found', __( 'Cloud storage or backup not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
	}
}
