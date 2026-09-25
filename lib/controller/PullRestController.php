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

use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\Runner;
use Founders\Migration\Model\Export\BackupOptions;
use Founders\Migration\Pull\PullException;
use Founders\Migration\Pull\PullKeys;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\ByteRange;
use Founders\Migration\Storage\Paths;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Multi-GB files are streamed with native file calls.

/**
 * The source side of a pull (namespace fmw/v1, routes under /pull).
 *
 * Every route needs a pull key in the X-FMW-Pull-Key header and nothing
 * else: no WordPress login, and the key opens no other route. With a key,
 * another site can make a backup of this site (driving it slice by slice,
 * so WP-Cron is not needed here), download it with byte ranges, and delete
 * it afterwards. See docs/pull-v1.md.
 */
final class PullRestController {

	const PROTOCOL    = 1;
	const KEY_HEADER  = 'X-FMW-Pull-Key';
	const BASE        = '/pull';
	const MAX_WAITING = 3; // Backups a key may leave on this site before it has to delete some.

	/**
	 * Key record of the current request (set by the permission check).
	 *
	 * @var array<string,mixed>|null
	 */
	private $key = null;

	/**
	 * Flags a pulling site may set for the backup.
	 *
	 * @var string[]
	 */
	const FLAGS = array(
		'exclude-spam-comments',
		'exclude-post-revisions',
		'exclude-transients',
		'exclude-media',
		'exclude-themes',
		'exclude-inactive-themes',
		'exclude-muplugins',
		'exclude-plugins',
		'exclude-inactive-plugins',
		'exclude-cache',
		'exclude-database',
		'exclude-tables',
		'exclude-paths',
		'part-size',
	);

	/**
	 * Registers hooks (nothing when FMWP_DISABLE_PULL is set).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! PullKeys::disabled() ) {
			add_action( 'rest_api_init', array( $this, 'routes' ) );
			add_filter( 'rest_post_dispatch', array( $this, 'no_store' ), 10, 3 );
		}
	}

	/**
	 * No cache may keep a pull answer, errors included.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param WP_REST_Request   $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function no_store( $response, $server, $request ) {
		unset( $server );
		if ( 0 === strpos( (string) $request->get_route(), '/' . RestController::NAMESPACE_V1 . self::BASE ) ) {
			$response->header( 'Cache-Control', 'no-store, private' );
		}
		return $response;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function routes(): void {
		$auth = array( $this, 'authenticate' );
		$ns   = RestController::NAMESPACE_V1;
		$job  = self::BASE . '/jobs/' . RestController::JOB_ID;
		$file = self::BASE . '/backups/(?P<name>[A-Za-z0-9._-]+\.fmw)';

		register_rest_route( $ns, self::BASE, array( array( 'methods' => 'GET', 'callback' => array( $this, 'info' ), 'permission_callback' => $auth ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( $ns, self::BASE . '/backups', array( array( 'methods' => 'POST', 'callback' => array( $this, 'start_backup' ), 'permission_callback' => $auth ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( $ns, $job, array( array( 'methods' => 'GET', 'callback' => array( $this, 'get_job' ), 'permission_callback' => $auth ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( $ns, $job . '/run', array( array( 'methods' => 'POST', 'callback' => array( $this, 'run_job' ), 'permission_callback' => $auth ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( $ns, $job . '/cancel', array( array( 'methods' => 'POST', 'callback' => array( $this, 'cancel_job' ), 'permission_callback' => $auth ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route(
			$ns,
			$file,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'backup_info' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_backup' ),
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route( $ns, $file . '/file', array( array( 'methods' => 'GET', 'callback' => array( $this, 'send_file' ), 'permission_callback' => $auth ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
	}

	/**
	 * Permission: a valid pull key for this address.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authenticate( WP_REST_Request $request ) {
		try {
			$this->key = PullKeys::authenticate( (string) $request->get_header( self::KEY_HEADER ), self::client_ip() );
		} catch ( PullException $e ) {
			return new WP_Error( $e->error_code, $e->getMessage(), array( 'status' => $e->status ) );
		}
		PullKeys::touch( (string) $this->key['id'], self::client_ip() );
		return true;
	}

	/**
	 * GET /pull: what the pulling site needs to know before it starts.
	 *
	 * @return WP_REST_Response
	 */
	public function info(): WP_REST_Response {
		global $wp_version;
		$data = array(
			'protocol' => self::PROTOCOL,
			'fmw'      => FMWP_VERSION,
			'format'   => 1,
			'site'     => array(
				'home_url'    => home_url(),
				'name'        => get_bloginfo( 'name' ),
				'wp_version'  => (string) $wp_version,
				'php_version' => PHP_VERSION,
				'multisite'   => is_multisite(),
			),
			'key'      => array(
				'name'           => (string) $this->key['name'],
				'expires_at'     => (int) $this->key['expires_at'],
				'allow_existing' => ! empty( $this->key['allow_existing'] ),
			),
		);
		if ( ! empty( $this->key['allow_existing'] ) ) {
			$data['backups'] = array();
			foreach ( Backups::all() as $backup ) {
				if ( 'fmw' === $backup['source'] && 'fmw' === $backup['type'] ) {
					$data['backups'][] = array(
						'name'  => $backup['name'],
						'size'  => $backup['size'],
						'mtime' => $backup['mtime'],
					);
				}
			}
		}
		return self::response( $data );
	}

	/**
	 * POST /pull/backups {flags, password}: starts a backup of this site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_backup( WP_REST_Request $request ) {
		if ( is_multisite() ) {
			return new WP_Error( 'fmw_pull_multisite', 'Pulling a multisite network arrives in a later version.', array( 'status' => 501 ) );
		}
		// One unfinished backup per key: a repeated start (a lost answer, a resumed pull) gets the same job.
		$store = Jobs::store();
		foreach ( (array) $this->key['jobs'] as $id ) {
			try {
				$existing = $store->load( (string) $id );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! $existing->is_finished() ) { // Failed ones too: running them again retries them.
				return self::response( self::summary( $existing ) );
			}
		}
		$waiting = 0;
		foreach ( (array) $this->key['backups'] as $name ) {
			$waiting += null === Backups::get( (string) $name ) ? 0 : 1;
		}
		if ( $waiting >= self::MAX_WAITING ) {
			return new WP_Error( 'fmw_pull_limit', sprintf( 'This key already left %d backups on the source site; delete them (or download them) before starting another.', $waiting ), array( 'status' => 409 ) );
		}
		$flags = array();
		foreach ( (array) $request['flags'] as $flag => $value ) {
			if ( ! in_array( $flag, self::FLAGS, true ) || ! is_scalar( $value ) ) {
				continue;
			}
			// Patterns and table names keep their characters; only control characters go.
			$flags[ $flag ] = is_bool( $value ) ? $value : (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value );
		}
		if ( is_string( $request['password'] ) && '' !== $request['password'] ) {
			$flags['password'] = $request['password']; // Raw: a password must not be "sanitized".
		}
		if ( null !== RestController::active_job( '' ) ) {
			return RestController::busy();
		}
		Paths::ensure_all();
		try {
			$options = BackupOptions::from_flags( $flags );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_option', $e->getMessage(), array( 'status' => 400 ) );
		}
		$options['pull_key'] = (string) $this->key['id'];
		$job                 = $store->create( 'backup', $options );
		PullKeys::own( (string) $this->key['id'], 'jobs', $job->id );
		$store->log( $job->id, sprintf( 'Started by pull key "%s" from %s.', $this->key['name'], self::client_ip() ) );
		return self::response( self::summary( $job ), 201 );
	}

	/**
	 * GET /pull/jobs/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$job = $this->owned_job( (string) $request['id'] );
		if ( $job instanceof WP_Error ) {
			return $job;
		}
		$this->own_backup( $job );
		return self::response( self::summary( $job ) );
	}

	/**
	 * POST /pull/jobs/<id>/run: works one time slice on the backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_job( WP_REST_Request $request ) {
		$job = $this->owned_job( (string) $request['id'] );
		if ( $job instanceof WP_Error ) {
			return $job;
		}
		if ( ! $job->is_finished() ) {
			if ( null !== RestController::active_job( $job->id ) ) {
				return RestController::busy();
			}
			ignore_user_abort( true ); // The job checkpoints; a dropped connection must not stop a slice half-way.
			$seconds = RestController::slice_seconds();
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( (int) $seconds + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled on some hosts.
			}
			$store = Jobs::store();
			try {
				$job = ( new Runner( $store, Jobs::registry() ) )->run( $job, new Deadline( $seconds ) );
			} catch ( JobException $e ) {
				return self::response( self::summary( $store->load( $job->id ) ) + array( 'busy' => true ) );
			}
			if ( Job::STATUS_COMPLETED === $job->status ) {
				$store->purge_work_files( $job->id );
			}
		}
		$this->own_backup( $job );
		return self::response( self::summary( $job ) );
	}

	/**
	 * Records the backup of a completed job as the key's (whichever route saw it complete first).
	 *
	 * @param Job $job Job of this key.
	 * @return void
	 */
	private function own_backup( Job $job ): void {
		if ( Job::STATUS_COMPLETED === $job->status && isset( $job->data['archive']['name'] ) ) {
			PullKeys::own( (string) $this->key['id'], 'backups', (string) $job->data['archive']['name'] );
			$this->key['backups'][] = (string) $job->data['archive']['name'];
		}
	}

	/**
	 * POST /pull/jobs/<id>/cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ) {
		$job = $this->owned_job( (string) $request['id'] );
		if ( $job instanceof WP_Error ) {
			return $job;
		}
		$store = Jobs::store();
		if ( ! $job->is_finished() ) {
			$store->request_cancel( $job->id );
			try {
				$job = ( new Runner( $store, Jobs::registry() ) )->run( $job, new Deadline( 5.0 ) ); // Applies the cancellation now.
			} catch ( JobException $e ) {
				unset( $e ); // A running slice picks it up.
			}
		}
		$job = $store->load( $job->id );
		$this->own_backup( $job );
		return self::response( self::summary( $job ) );
	}

	/**
	 * GET /pull/backups/<name>: size and version tag of a backup this key may download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function backup_info( WP_REST_Request $request ) {
		$backup = $this->allowed_backup( (string) $request['name'] );
		if ( $backup instanceof WP_Error ) {
			return $backup;
		}
		return self::response(
			array(
				'name'  => $backup['name'],
				'size'  => $backup['size'],
				'mtime' => $backup['mtime'],
				'etag'  => self::etag( $backup ),
			)
		);
	}

	/**
	 * DELETE /pull/backups/<name>: removes a backup this key made (after the download).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_backup( WP_REST_Request $request ) {
		$name = (string) $request['name'];
		if ( ! in_array( $name, (array) $this->key['backups'], true ) ) {
			return new WP_Error( 'fmw_pull_forbidden', 'Only backups made with this pull key can be deleted with it.', array( 'status' => 403 ) );
		}
		$deleted = Backups::delete( $name );
		return self::response( array( 'deleted' => $deleted ) );
	}

	/**
	 * GET /pull/backups/<name>/file: the backup's bytes (one byte range per request).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error Only on failure: on success the bytes are streamed and the request ends here.
	 */
	public function send_file( WP_REST_Request $request ) {
		$backup = $this->allowed_backup( (string) $request['name'] );
		if ( $backup instanceof WP_Error ) {
			return $backup;
		}
		$etag  = self::etag( $backup );
		$match = trim( (string) $request->get_header( 'if_match' ), " \t\n\r\0\x0B" );
		if ( '' !== $match && '*' !== $match && ! in_array( $etag, array_map( 'trim', explode( ',', $match ) ), true ) ) {
			return new WP_Error( 'fmw_pull_changed', 'The backup changed since the download started.', array( 'status' => 412 ) );
		}
		$size  = (int) $backup['size'];
		$range = ByteRange::parse( (string) $request->get_header( 'range' ), $size );
		if ( false === $range ) {
			return new WP_Error( 'fmw_pull_range', 'The byte range is outside the backup.', array( 'status' => 416 ) );
		}
		list( $start, $end ) = null === $range ? array( 0, $size - 1 ) : $range;
		$handle              = fopen( $backup['path'], 'rb' );
		if ( false === $handle || ( $start > 0 && 0 !== fseek( $handle, $start ) ) ) {
			return new WP_Error( 'fmw_pull_read', 'The backup could not be read.', array( 'status' => 500 ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		ignore_user_abort( false );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled on some hosts.
		}
		status_header( null === $range ? 200 : 206 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Accept-Ranges: bytes' );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( null !== $range ) {
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}
		$left = 'HEAD' === $request->get_method() ? 0 : $end - $start + 1;
		$sent = 0;
		while ( $left > 0 && ! connection_aborted() ) {
			$data = fread( $handle, (int) min( 1048576, $left ) );
			if ( false === $data || '' === $data ) {
				break;
			}
			echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file download.
			flush();
			$left -= strlen( $data );
			$sent += strlen( $data );
		}
		fclose( $handle );
		PullKeys::touch( (string) $this->key['id'], self::client_ip(), $sent );
		exit;
	}

	/**
	 * A job this key started.
	 *
	 * @param string $id Job id.
	 * @return Job|WP_Error
	 */
	private function owned_job( string $id ) {
		if ( ! in_array( $id, (array) $this->key['jobs'], true ) ) {
			return new WP_Error( 'fmw_not_found', 'Job not found.', array( 'status' => 404 ) );
		}
		try {
			return Jobs::store()->load( $id );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'fmw_not_found', 'Job not found.', array( 'status' => 404 ) );
		}
	}

	/**
	 * A backup this key may download: one it made, or any .fmw backup with --allow-existing.
	 *
	 * @param string $name Backup name.
	 * @return array{name:string,path:string,size:int,mtime:int,type:string,source:string}|WP_Error
	 */
	private function allowed_backup( string $name ) {
		$backup = Backups::get( $name );
		$mine   = in_array( $name, (array) $this->key['backups'], true );
		if ( null === $backup || 'fmw' !== $backup['source'] || 'fmw' !== $backup['type'] || ( ! $mine && empty( $this->key['allow_existing'] ) ) ) {
			return new WP_Error( 'fmw_not_found', 'Backup not found, or not available with this pull key.', array( 'status' => 404 ) );
		}
		return $backup;
	}

	/**
	 * What the pulling site sees of a job.
	 *
	 * @param Job $job Job.
	 * @return array<string,mixed>
	 */
	private static function summary( Job $job ): array {
		$summary = array(
			'id'          => $job->id,
			'status'      => $job->status,
			'phase'       => $job->phase,
			'progress'    => $job->progress(),
			'bytes_done'  => $job->bytes_done,
			'bytes_total' => $job->bytes_total,
			'error'       => $job->error,
		);
		if ( Job::STATUS_COMPLETED === $job->status && isset( $job->data['archive']['name'] ) ) {
			$summary['backup'] = array(
				'name' => (string) $job->data['archive']['name'],
				'size' => (int) ( $job->data['archive']['bytes'] ?? 0 ),
			);
		}
		return $summary;
	}

	/**
	 * Version tag of a backup file (changes when it is replaced).
	 *
	 * @param array{name:string,size:int,mtime:int} $backup Backup.
	 * @return string
	 */
	private static function etag( array $backup ): string {
		return '"' . substr( hash( 'sha256', $backup['name'] . '|' . $backup['size'] . '|' . $backup['mtime'] ), 0, 32 ) . '"';
	}

	/**
	 * JSON response that no cache keeps.
	 *
	 * @param array<string,mixed> $data   Data.
	 * @param int                 $status Status.
	 * @return WP_REST_Response
	 */
	private static function response( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * The client's address. Behind a proxy or CDN, REMOTE_ADDR is the proxy;
	 * sites that trust their proxy can return the real address with the
	 * fmwp_pull_client_ip filter (X-Forwarded-For is never trusted by default).
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the address pull keys are checked against.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'fmwp_pull_client_ip', $ip );
	}
}
