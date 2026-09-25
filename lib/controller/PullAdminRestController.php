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

use Founders\Migration\Pull\PullClient;
use Founders\Migration\Pull\PullException;
use Founders\Migration\Pull\PullKeys;
use Founders\Migration\Pull\PullOptions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * REST API behind the Pull screen (namespace fmw/v1, plugin capability,
 * cookie + nonce): pull keys of this site, checking a source site, and the
 * options of a pull job (created through POST /jobs with type "pull").
 */
final class PullAdminRestController {

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
		$admin = static function (): bool {
			return current_user_can( fmwp_capability() );
		};
		$ns    = RestController::NAMESPACE_V1;
		register_rest_route(
			$ns,
			'/pull-keys',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_keys' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_key' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route( $ns, '/pull-keys/(?P<id>[0-9a-f]{8})', array( array( 'methods' => 'DELETE', 'callback' => array( $this, 'revoke_key' ), 'permission_callback' => $admin ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
		register_rest_route( $ns, '/pull-check', array( array( 'methods' => 'POST', 'callback' => array( $this, 'check_source' ), 'permission_callback' => $admin ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Route table.
	}

	/**
	 * GET /pull-keys.
	 *
	 * @return WP_REST_Response
	 */
	public function list_keys(): WP_REST_Response {
		$keys = array();
		foreach ( PullKeys::all() as $key ) {
			$keys[] = array(
				'id'             => (string) $key['id'],
				'name'           => (string) $key['name'],
				'expires'        => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $key['expires_at'] ),
				'expired'        => (int) $key['expires_at'] <= time(),
				'ips'            => array_values( (array) $key['ips'] ),
				'allow_existing' => ! empty( $key['allow_existing'] ),
				'last_used'      => empty( $key['last_used_at'] ) ? '' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $key['last_used_at'] ),
				'last_ip'        => (string) $key['last_ip'],
				'bytes_sent'     => (int) ( $key['bytes_sent'] ?? 0 ),
			);
		}
		return new WP_REST_Response(
			array(
				'disabled' => PullKeys::disabled(),
				'site'     => home_url(),
				'keys'     => $keys,
			)
		);
	}

	/**
	 * POST /pull-keys {name, expires (seconds), ips (text), allow_existing}: the key is in this answer only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_key( WP_REST_Request $request ) {
		if ( PullKeys::disabled() ) {
			return new WP_Error( 'fmw_pull_disabled', __( 'Pulls are switched off on this site (FMWP_DISABLE_PULL).', 'founders-migration-website' ), array( 'status' => 403 ) );
		}
		try {
			$made = PullKeys::create(
				array(
					'name'           => sanitize_text_field( (string) $request['name'] ),
					'ttl'            => (int) $request['expires'],
					'ips'            => preg_split( '/[\s,]+/', (string) $request['ips'], -1, PREG_SPLIT_NO_EMPTY ),
					'allow_existing' => ! empty( $request['allow_existing'] ),
					'user'           => get_current_user_id(),
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'fmw_invalid_option', $e->getMessage(), array( 'status' => 400 ) );
		}
		$response = new WP_REST_Response(
			array(
				'id'      => (string) $made['record']['id'],
				'key'     => $made['key'],
				'site'    => home_url(),
				'expires' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $made['record']['expires_at'] ),
				'command' => sprintf( 'wp fmw pull %s --key=%s', home_url(), $made['key'] ),
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * DELETE /pull-keys/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_key( WP_REST_Request $request ) {
		if ( ! PullKeys::revoke( (string) $request['id'] ) ) {
			return new WP_Error( 'fmw_not_found', __( 'Pull key not found.', 'founders-migration-website' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( array( 'revoked' => (string) $request['id'] ) );
	}

	/**
	 * POST /pull-check {url, key, allow_http, backup}: what the source says, before anything starts (network: sites and kind, for networks).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_source( WP_REST_Request $request ) {
		try {
			$client = ( new PullClient( (string) $request['url'], (string) $request['key'], ! empty( $request['allow_http'] ) ) )->quick();
			$info   = PullOptions::check( $client, sanitize_file_name( (string) $request['backup'] ), ! empty( $request['download_only'] ) );
		} catch ( PullException $e ) {
			return new WP_Error( $e->error_code, $e->getMessage(), array( 'status' => 400 ) );
		}
		$site    = (array) ( $info['site'] ?? array() );
		$backups = array();
		foreach ( array_slice( (array) ( $info['backups'] ?? array() ), 0, 200 ) as $backup ) {
			if ( is_array( $backup ) && '' !== sanitize_file_name( (string) ( $backup['name'] ?? '' ) ) ) {
				$backups[] = array(
					'name' => sanitize_file_name( (string) $backup['name'] ),
					'size' => (int) ( $backup['size'] ?? 0 ),
				);
			}
		}
		$expires = (int) ( $info['key']['expires_at'] ?? 0 );
		$network = is_array( $site['network'] ?? null ) ? array(
			'sites'     => (int) ( $site['network']['sites'] ?? 0 ),
			'subdomain' => ! empty( $site['network']['subdomain'] ),
		) : null;
		return new WP_REST_Response(
			array(
				'url'            => $client->url(),
				'home_url'       => (string) ( $site['home_url'] ?? '' ),
				'name'           => (string) ( $site['name'] ?? '' ),
				'wp_version'     => (string) ( $site['wp_version'] ?? '' ),
				'php_version'    => (string) ( $site['php_version'] ?? '' ),
				'fmw'            => (string) ( $info['fmw'] ?? '' ),
				'same_version'   => (string) ( $info['fmw'] ?? '' ) === FMWP_VERSION,
				'expires'        => $expires > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires ) : '',
				'allow_existing' => ! empty( $info['key']['allow_existing'] ),
				'backups'        => $backups,
				'network'        => $network,
				'target'         => home_url(),
			)
		);
	}

	/**
	 * Type and options of a pull job from POST /jobs {type: pull, url, key,
	 * allow_http, backup, password, download_only, keep_source, flags}.
	 *
	 * @param WP_REST_Request     $request Request.
	 * @param array<string,mixed> $flags   Sanitized flags (exclusions and restore options).
	 * @return array{0:string,1:array<string,mixed>}|WP_Error
	 */
	public static function job( WP_REST_Request $request, array $flags ) {
		$password = is_string( $request['password'] ) ? $request['password'] : '';
		$backup   = sanitize_file_name( (string) $request['backup'] );
		if ( '' !== $password && '' === $backup && strlen( $password ) < 8 ) {
			return new WP_Error( 'fmw_invalid_option', __( 'The password must be at least 8 characters long.', 'founders-migration-website' ), array( 'status' => 400 ) );
		}
		try {
			$client = ( new PullClient( (string) $request['url'], (string) $request['key'], ! empty( $request['allow_http'] ) ) )->quick();
			PullOptions::check( $client, $backup, ! empty( $request['download_only'] ) );
		} catch ( PullException $e ) {
			return new WP_Error( $e->error_code, $e->getMessage(), array( 'status' => 400 ) );
		}
		$download_only = ! empty( $request['download_only'] );
		$options       = PullOptions::build(
			$client->url(),
			(string) $request['key'],
			array(
				'allow_http'  => ! empty( $request['allow_http'] ),
				'flags'       => $flags,
				'password'    => $password,
				'backup'      => $backup,
				'keep_source' => ! empty( $request['keep_source'] ),
				'restore'     => $download_only ? null : $flags,
			)
		);
		return array( PullOptions::type( $download_only ), $options );
	}
}
