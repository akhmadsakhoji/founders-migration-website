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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Archive\PasswordException;
use Founders\Migration\Archive\WpressDecoder;
use Founders\Migration\Archive\WpressPackage;
use Founders\Migration\Archive\WpressReader;
use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\JobStore;
use Founders\Migration\Job\JobToken;
use Founders\Migration\Job\Lock;
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Model\Export\BackupOptions;
use Founders\Migration\Model\Import\RestoreOptions;
use Founders\Migration\Model\Reset\ResetOptions;
use Founders\Migration\Schedule\Background;
use Founders\Migration\Schedule\Scheduler;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\Paths;
use Founders\Migration\Storage\UploadOffsetException;
use Founders\Migration\Storage\Uploads;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * REST API behind the admin screens (namespace fmw/v1).
 *
 * Everything needs the plugin capability (cookie + nonce), except running,
 * reading and cancelling a job, which also accept the job's own token (the
 * X-FMW-Token header). A restore replaces the users table, so the browser's
 * login usually stops being valid half-way; the token, stored hashed with
 * the job outside the database, lets that browser finish the job it started.
 */
final class RestController {

	const NAMESPACE_V1 = 'fmw/v1';
	const JOB_ID       = '(?P<id>[0-9A-HJKMNP-TV-Z]{26})';
	const TOKEN_HEADER = 'X-FMW-Token';
	const CHUNK_BYTES  = 8388608;

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
		$admin = array( $this, 'can_manage' );
		$job   = array( $this, 'can_run_job' );

		register_rest_route( self::NAMESPACE_V1, '/backups', array( array( 'methods' => 'GET', 'callback' => array( $this, 'list_backups' ), 'permission_callback' => $admin ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( self::NAMESPACE_V1, '/backups/(?P<name>[^/]+)', array( array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_backup' ), 'permission_callback' => $admin ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( self::NAMESPACE_V1, '/backups/(?P<name>[^/]+)/inspect', array( array( 'methods' => 'GET', 'callback' => array( $this, 'inspect_backup' ), 'permission_callback' => $admin ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.

		register_rest_route( self::NAMESPACE_V1, '/uploads', array( array( 'methods' => 'POST', 'callback' => array( $this, 'open_upload' ), 'permission_callback' => $admin ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route(
			self::NAMESPACE_V1,
			'/uploads/(?P<id>[a-f0-9]{32})',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'append_upload' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'discard_upload' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route( self::NAMESPACE_V1, '/uploads/(?P<id>[a-f0-9]{32})/complete', array( array( 'methods' => 'POST', 'callback' => array( $this, 'complete_upload' ), 'permission_callback' => $admin ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_jobs' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_job' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route( self::NAMESPACE_V1, '/jobs/' . self::JOB_ID, array( array( 'methods' => 'GET', 'callback' => array( $this, 'get_job' ), 'permission_callback' => $job ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( self::NAMESPACE_V1, '/jobs/' . self::JOB_ID . '/run', array( array( 'methods' => 'POST', 'callback' => array( $this, 'run_job' ), 'permission_callback' => $job ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( self::NAMESPACE_V1, '/jobs/' . self::JOB_ID . '/cancel', array( array( 'methods' => 'POST', 'callback' => array( $this, 'cancel_job' ), 'permission_callback' => $job ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( self::NAMESPACE_V1, '/jobs/' . self::JOB_ID . '/token', array( array( 'methods' => 'POST', 'callback' => array( $this, 'renew_token' ), 'permission_callback' => $admin ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
	}

	/**
	 * Permission: the plugin capability.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( fmwp_capability() );
	}

	/**
	 * Permission: the plugin capability, or the job's token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_run_job( WP_REST_Request $request ): bool {
		if ( $this->can_manage() ) {
			return true;
		}
		$token = (string) $request->get_header( self::TOKEN_HEADER );
		$store = Jobs::store();
		try {
			$job = $store->load( (string) $request['id'] );
		} catch ( \Throwable $e ) {
			return false;
		}
		return JobToken::matches( $job, $store, $token );
	}

	/**
	 * GET /backups.
	 *
	 * @return WP_REST_Response
	 */
	public function list_backups(): WP_REST_Response {
		$items = array();
		foreach ( Backups::all() as $backup ) {
			$items[] = array(
				'name'       => $backup['name'],
				'size'       => $backup['size'],
				'mtime'      => $backup['mtime'],
				'date'       => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['mtime'] ),
				'type'       => $backup['type'],
				'source'     => $backup['source'],
				'can_delete' => 'fmw' === $backup['source'],
			);
		}
		return new WP_REST_Response( $items );
	}

	/**
	 * DELETE /backups/<name>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_backup( WP_REST_Request $request ) {
		$name   = rawurldecode( (string) $request['name'] );
		$backup = Backups::get( $name );
		if ( null === $backup ) {
			return new WP_Error( 'fmw_not_found', __( 'Backup not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
		}
		if ( 'fmw' !== $backup['source'] ) {
			return new WP_Error( 'fmw_forbidden', __( 'This backup belongs to All-in-One WP Migration; delete it there.', 'founders-migration-website' ), array( 'status' => 403 ) );
		}
		if ( ! Backups::delete( $name ) ) {
			return new WP_Error( 'fmw_delete_failed', __( 'The backup could not be deleted.', 'founders-migration-website' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'deleted' => $name ) );
	}

	/**
	 * GET /backups/<name>/inspect: what the confirmation needs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function inspect_backup( WP_REST_Request $request ) {
		$backup = Backups::get( rawurldecode( (string) $request['name'] ) );
		if ( null === $backup ) {
			return new WP_Error( 'fmw_not_found', __( 'Backup not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
		}
		try {
			if ( 'wpress' === $backup['type'] ) {
				$package = WpressPackage::read( $backup['path'] );
				new WpressDecoder( null, $package->compression() );
				$data = $package->data();
				return new WP_REST_Response(
					array(
						'name'      => $backup['name'],
						'format'    => 'wpress',
						'site_url'  => (string) ( $data['HomeURL'] ?? '' ),
						'generator' => 'All-in-One WP Migration ' . $package->plugin_version(),
						'created'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['mtime'] ),
						'encrypted' => $package->encrypted(),
						'size'      => $backup['size'],
					)
				);
			}
			$archive = new FmwArchive( $backup['path'] );
			$header  = $archive->header();
			if ( ! empty( $header['encrypted'] ) ) {
				return new WP_REST_Response(
					array(
						'name'      => $backup['name'],
						'format'    => 'fmw',
						'site_url'  => '',
						'generator' => (string) ( $header['generator'] ?? '' ),
						'created'   => isset( $header['created_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( (string) $header['created_at'] ) ) : '',
						'encrypted' => true,
						'size'      => $backup['size'],
					)
				);
			}
			$manifest = $archive->manifest();
		} catch ( ArchiveException $e ) {
			return new WP_Error( 'fmw_invalid_backup', $e->getMessage(), array( 'status' => 422 ) );
		}
		return new WP_REST_Response(
			array(
				'name'      => $backup['name'],
				'format'    => 'fmw',
				'site_url'  => (string) ( $manifest['site']['home_url'] ?? '' ),
				'generator' => (string) ( $manifest['generator'] ?? '' ),
				'created'   => isset( $manifest['created_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( (string) $manifest['created_at'] ) ) : '',
				'encrypted' => ! empty( $manifest['options']['encrypted'] ),
				'size'      => $backup['size'],
			)
		);
	}

	/**
	 * POST /uploads {name, size}: starts or resumes an upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function open_upload( WP_REST_Request $request ) {
		Paths::ensure_all();
		$uploads = self::uploads();
		$uploads->remove_stale( time() );
		try {
			$status = $uploads->open( (string) $request['name'], (int) $request['size'], get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_upload', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'fmw_upload_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		$free = @disk_free_space( fmwp_storage_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not available on every host.
		if ( false !== $free && $free < $status['size'] - $status['offset'] ) {
			return new WP_Error( 'fmw_no_space', __( 'Not enough free disk space for this file.', 'founders-migration-website' ), array( 'status' => 507 ) );
		}
		unset( $status['user'] );
		$status['chunk'] = self::chunk_bytes();
		return new WP_REST_Response( $status );
	}

	/**
	 * POST /uploads/<id>: raw chunk body, X-FMW-Offset header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function append_upload( WP_REST_Request $request ) {
		$uploads = self::uploads();
		try {
			$status = $uploads->status( (string) $request['id'] );
			if ( get_current_user_id() !== $status['user'] ) {
				return new WP_Error( 'fmw_not_found', __( 'Unknown upload.', 'founders-migration-website' ), array( 'status' => 404 ) );
			}
			$offset = $uploads->append( (string) $request['id'], (int) $request->get_header( 'X-FMW-Offset' ), (string) $request->get_body() );
		} catch ( UploadOffsetException $e ) {
			return new WP_Error( 'fmw_offset', $e->getMessage(), array( 'status' => 409, 'offset' => $e->offset ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short error data.
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_upload', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'fmw_upload_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'offset' => $offset ) );
	}

	/**
	 * POST /uploads/<id>/complete: checks the file and moves it into the backups folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_upload( WP_REST_Request $request ) {
		$uploads = self::uploads();
		try {
			$status = $uploads->status( (string) $request['id'] );
			if ( get_current_user_id() !== $status['user'] ) {
				return new WP_Error( 'fmw_not_found', __( 'Unknown upload.', 'founders-migration-website' ), array( 'status' => 404 ) );
			}
			$path = null;
			for ( $attempt = 0; null === $path && $attempt < 4; $attempt++ ) {
				$name = Backups::unique_name( $status['name'] );
				try {
					$path = $uploads->complete( (string) $request['id'], fmwp_backups_path(), $name );
				} catch ( \RuntimeException $e ) {
					if ( ! file_exists( fmwp_backups_path() . '/' . $name ) ) {
						return new WP_Error( 'fmw_upload_failed', $e->getMessage(), array( 'status' => 500 ) );
					}
					// Another upload took the name at the same moment: pick the next free one.
				}
			}
			if ( null === $path ) {
				return new WP_Error( 'fmw_upload_failed', __( 'The upload could not be moved into the backups folder.', 'founders-migration-website' ), array( 'status' => 500 ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_upload', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'fmw_upload_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		// Refuse files that are not backups at all, before anyone tries to restore them.
		try {
			if ( 'wpress' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				WpressReader::end_block( $path );
				WpressPackage::read( $path );
			} else {
				( new FmwArchive( $path ) )->manifest();
			}
		} catch ( ArchiveException $e ) {
			wp_delete_file( $path );
			return new WP_Error( 'fmw_invalid_backup', $e->getMessage(), array( 'status' => 422 ) );
		}
		return new WP_REST_Response( array( 'backup' => $name ) );
	}

	/**
	 * DELETE /uploads/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function discard_upload( WP_REST_Request $request ): WP_REST_Response {
		$uploads = self::uploads();
		try {
			if ( get_current_user_id() === $uploads->status( (string) $request['id'] )['user'] ) {
				$uploads->discard( (string) $request['id'] );
			}
		} catch ( \InvalidArgumentException $e ) {
			unset( $e ); // Already gone.
		}
		return new WP_REST_Response( array( 'discarded' => true ) );
	}

	/**
	 * GET /jobs: unfinished jobs, newest first.
	 *
	 * @return WP_REST_Response
	 */
	public function list_jobs(): WP_REST_Response {
		$store = Jobs::store();
		$items = array();
		foreach ( $store->all() as $job ) {
			if ( ! $job->is_finished() ) {
				$items[] = self::summary( $job, $store );
			}
		}
		return new WP_REST_Response( $items );
	}

	/**
	 * POST /jobs: {type: backup, flags}, {type: restore, backup, password, flags} or {type: reset, parts, confirm, flags}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( WP_REST_Request $request ) {
		$flags = array();
		foreach ( (array) $request['flags'] as $flag => $value ) {
			if ( is_string( $flag ) && 1 === preg_match( '/^[a-z][a-z-]{1,40}$/', $flag ) ) {
				$flags[ $flag ] = is_bool( $value ) ? $value : sanitize_text_field( (string) $value );
			}
		}
		if ( null !== self::active_job( '' ) ) {
			return self::busy();
		}

		Paths::ensure_all();
		$type = (string) $request['type'];
		try {
			unset( $flags['password'] ); // Only the top-level, unsanitized password counts.
			if ( 'backup' === $type ) {
				if ( is_string( $request['password'] ) && '' !== $request['password'] ) {
					$flags['password'] = $request['password']; // Raw: a password must not be "sanitized".
				}
				$options = BackupOptions::from_flags( $flags );
			} elseif ( 'restore' === $type ) {
				$backup = Backups::get( (string) $request['backup'] );
				if ( null === $backup ) {
					return new WP_Error( 'fmw_not_found', __( 'Backup not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
				}
				$options = RestoreOptions::build( $backup['path'], $flags );
				if ( 'fmw' === $backup['type'] ) {
					$archive = new FmwArchive( $backup['path'] );
					if ( $archive->encrypted() ) {
						$password = (string) $request['password'];
						$archive->manifest( $password ); // Refuses a missing or wrong password now.
						$options['secret_password'] = Secrets::seal( $password );
					}
				}
				if ( 'wpress' === $backup['type'] ) {
					$type    = 'restore-wpress';
					$package = WpressPackage::read( $backup['path'] );
					if ( $package->encrypted() ) {
						$password = (string) $request['password'];
						if ( '' === $password ) {
							return new WP_Error( 'fmw_password_required', __( 'This backup is protected with a password.', 'founders-migration-website' ), array( 'status' => 400 ) );
						}
						$options['secret_wpress_key'] = Secrets::seal( $package->key_for( $password ) );
					}
				}
			} elseif ( 'reset' === $type ) {
				if ( ! ResetOptions::confirmed( (string) $request['confirm'] ) ) {
					/* translators: %s: site host name. */
					return new WP_Error( 'fmw_not_confirmed', sprintf( __( 'Type %s to confirm the reset.', 'founders-migration-website' ), ResetOptions::confirm_word() ), array( 'status' => 400 ) );
				}
				$parts   = array_values( array_filter( (array) $request['parts'], 'is_string' ) );
				$options = ResetOptions::build( $parts, array( get_current_user_id() ), ! empty( $flags['keep-old-tables'] ) );
			} else {
				return new WP_Error( 'fmw_invalid_type', __( 'Unknown job type.', 'founders-migration-website' ), array( 'status' => 400 ) );
			}
		} catch ( PasswordException $e ) {
			return new WP_Error( $e->given ? 'fmw_wrong_password' : 'fmw_password_required', $e->given ? __( 'Wrong password for this backup.', 'founders-migration-website' ) : __( 'This backup is protected with a password.', 'founders-migration-website' ), array( 'status' => 400 ) );
		} catch ( ArchiveException $e ) {
			$code = false !== strpos( $e->getMessage(), 'Wrong password' ) ? 'fmw_wrong_password' : 'fmw_invalid_backup';
			return new WP_Error( $code, $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_option', $e->getMessage(), array( 'status' => 400 ) );
		}

		$store = Jobs::store();
		$job   = $store->create( $type, $options );
		$token = JobToken::issue( $job, $store );
		return new WP_REST_Response( self::summary( $job, $store ) + array( 'token' => $token ), 201 );
	}

	/**
	 * GET /jobs/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$store = Jobs::store();
		try {
			$job = $store->load( (string) $request['id'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'fmw_not_found', __( 'Job not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::summary( $job, $store ) );
	}

	/**
	 * POST /jobs/<id>/run: runs one time slice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_job( WP_REST_Request $request ) {
		$store = Jobs::store();
		try {
			$job = $store->load( (string) $request['id'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'fmw_not_found', __( 'Job not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
		}
		if ( $job->is_finished() ) {
			return new WP_REST_Response( self::summary( $job, $store ) );
		}
		if ( null !== self::active_job( $job->id ) ) {
			return self::busy(); // Never interleave slices of two jobs (for example two restores).
		}

		ignore_user_abort( true ); // A closed tab must not stop a slice half-way; the job checkpoints anyway.
		$seconds = self::slice_seconds();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) $seconds + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled on some hosts.
		}

		try {
			$job = ( new Runner( $store, Jobs::registry() ) )->run( $job, new Deadline( $seconds ) );
		} catch ( JobException $e ) {
			// Another request is running this slice; the browser just asks again.
			$response = new WP_REST_Response( self::summary( $store->load( $job->id ), $store ) + array( 'busy' => true ) );
			return $response;
		}

		if ( Job::STATUS_COMPLETED === $job->status ) {
			$store->purge_work_files( $job->id );
			if ( Jobs::changes_site( $job->type ) ) {
				wp_cache_flush();
				delete_option( 'rewrite_rules' );
			}
		}
		if ( '' !== (string) ( $job->options['schedule_id'] ?? '' ) && ( $job->is_finished() || Job::STATUS_FAILED === $job->status ) ) {
			Scheduler::after( $job, true ); // Records the result, then starts the next due schedule.
		} elseif ( ! empty( $request['background'] ) && Job::STATUS_RUNNING === $job->status ) {
			$token = (string) $request->get_header( self::TOKEN_HEADER );
			if ( '' !== $token ) {
				Background::send( $job->id, $token ); // Next slice of a job that runs without a browser.
			}
		}
		return new WP_REST_Response( self::summary( $job, $store ) );
	}

	/**
	 * POST /jobs/<id>/cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ) {
		$store = Jobs::store();
		try {
			$job = $store->load( (string) $request['id'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'fmw_not_found', __( 'Job not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
		}
		if ( $job->is_finished() ) {
			return new WP_REST_Response( self::summary( $job, $store ) );
		}
		$store->request_cancel( $job->id );
		if ( ! Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
			try {
				$job = ( new Runner( $store, Jobs::registry() ) )->run( $job, new Deadline( 5.0 ) ); // Applies the cancellation now.
			} catch ( JobException $e ) {
				unset( $e ); // Picked up by the running slice.
			}
		}
		return new WP_REST_Response( self::summary( $store->load( $job->id ), $store ) );
	}

	/**
	 * POST /jobs/<id>/token: a new token, to continue a job from another browser.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function renew_token( WP_REST_Request $request ) {
		$store = Jobs::store();
		try {
			$job = $store->load( (string) $request['id'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'fmw_not_found', __( 'Job not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::summary( $job, $store ) + array( 'token' => JobToken::issue( $job, $store ) ) );
	}

	/**
	 * A job other than $except that is being worked on: its lock is held, or it checkpointed in the last two minutes.
	 *
	 * @param string $except Job id to ignore.
	 * @return Job|null
	 */
	public static function active_job( string $except ): ?Job {
		$store = Jobs::store();
		foreach ( $store->all() as $job ) {
			if ( $job->id === $except || $job->is_finished() ) {
				continue;
			}
			if ( Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) || ( Job::STATUS_RUNNING === $job->status && time() - $job->updated_at < 120 ) ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * The "another job is running" error.
	 *
	 * @return WP_Error
	 */
	public static function busy(): WP_Error {
		return new WP_Error( 'fmw_busy', __( 'Another backup or restore is running. Wait for it to finish, or cancel it on the Backups page.', 'founders-migration-website' ), array( 'status' => 409 ) );
	}

	/**
	 * What the browser shows about a job.
	 *
	 * @param Job      $job   Job.
	 * @param JobStore $store Store.
	 * @return array<string,mixed>
	 */
	public static function summary( Job $job, JobStore $store ): array {
		$list   = Jobs::registry()->steps( $job->type );
		$steps  = count( $list );
		$status = $job->status;
		$phase  = isset( $list[ $job->step ] ) && ! $job->is_finished() ? $list[ $job->step ]->label() : $job->phase; // The next step, once the previous one is done.
		if ( Job::STATUS_RUNNING === $status && ! Lock::is_held( $store->dir( $job->id ) . '/' . JobStore::LOCK_FILE ) ) {
			$status = 'interrupted';
		}
		$summary = array(
			'id'          => $job->id,
			'type'        => Jobs::is_restore( $job->type ) ? 'restore' : $job->type,
			'status'      => $status,
			'phase'       => $phase,
			'step'        => min( $job->step + 1, $steps ),
			'steps'       => $steps,
			'progress'    => $job->progress(),
			'bytes_done'  => $job->bytes_done,
			'bytes_total' => $job->bytes_total,
			'error'       => $job->error,
			'log'         => $store->log_lines( $job->id, 6 ),
			'updated'     => $job->updated_at,
		);
		if ( Job::STATUS_COMPLETED === $job->status && isset( $job->data['archive']['name'] ) ) {
			$summary['backup'] = array(
				'name' => (string) $job->data['archive']['name'],
				'size' => (int) ( $job->data['archive']['bytes'] ?? 0 ),
			);
		}
		if ( Jobs::is_restore( $job->type ) ) {
			$summary['archive'] = basename( (string) ( $job->options['archive'] ?? '' ) );
		}
		if ( '' !== (string) ( $job->options['schedule_name'] ?? '' ) ) {
			$summary['schedule'] = (string) $job->options['schedule_name'];
		}
		return $summary;
	}

	/**
	 * Time budget for one request: well inside max_execution_time, at most 20 seconds.
	 *
	 * @return float
	 */
	public static function slice_seconds(): float {
		$limit   = (int) ini_get( 'max_execution_time' );
		$seconds = (float) ( $limit > 0 ? max( 5, min( 20, $limit - 10 ) ) : 20 );

		/**
		 * Seconds one browser request may work on a job (for hosts with strict proxy timeouts).
		 *
		 * @param float $seconds Default: 20, or less when max_execution_time is lower.
		 */
		return max( 1.0, (float) apply_filters( 'fmwp_web_slice_seconds', $seconds ) );
	}

	/**
	 * Chunk size for uploads: 8 MB, less when post_max_size is smaller.
	 *
	 * @return int
	 */
	public static function chunk_bytes(): int {
		$post = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( $post > 0 ) {
			return (int) max( 262144, min( self::CHUNK_BYTES, $post - 65536 ) );
		}
		return self::CHUNK_BYTES;
	}

	/**
	 * Upload store.
	 *
	 * @return Uploads
	 */
	private static function uploads(): Uploads {
		return new Uploads( fmwp_storage_path() . '/uploads' );
	}
}
