<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Remote;

use Founders\Migration\Job\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Google sign-in (OAuth 2.0 with PKCE) for a Google Drive storage.
 *
 * Mode "own" (the only one for now): the site's own OAuth client from the
 * Google Cloud Console, so no server of anyone else is involved. The scope
 * is drive.file: the plugin sees only the files and folders it created,
 * nothing else in the Drive. The refresh token and the current access token
 * are kept sealed with the site's salts in the storage record. The storage
 * format has an "auth" field so that a hosted sign-in service (a relay) can
 * be added later without changing how storages are saved.
 */
final class GoogleAuth {

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const SCOPE     = 'https://www.googleapis.com/auth/drive.file';
	const ACTION    = 'fmwp_gdrive_callback';
	const STATE_TTL = 1200;

	/**
	 * A Google endpoint (filterable, for a test server).
	 *
	 * @param string $name auth, token, revoke, drive or upload.
	 * @return string
	 */
	public static function endpoint( string $name ): string {
		$endpoints = array(
			'auth'   => self::AUTH_URL,
			'token'  => self::TOKEN_URL,
			'revoke' => 'https://oauth2.googleapis.com/revoke',
			'drive'  => 'https://www.googleapis.com/drive/v3',
			'upload' => 'https://www.googleapis.com/upload/drive/v3',
		);
		/**
		 * Filters the Google endpoints (only for testing against a local server).
		 *
		 * @param array<string,string> $endpoints Endpoints.
		 */
		$endpoints = function_exists( 'apply_filters' ) ? (array) apply_filters( 'fmwp_google_endpoints', $endpoints ) : $endpoints;
		return rtrim( (string) ( $endpoints[ $name ] ?? '' ), '/' );
	}

	/**
	 * The redirect URI to register in the Google Cloud Console.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION );
	}

	/**
	 * Google's consent page for a storage (the browser goes there, then comes back to the redirect URI).
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @param int                 $user    User who starts it (0: any administrator may finish it, for WP-CLI).
	 * @return string
	 * @throws RemoteException When the storage cannot sign in this way.
	 */
	public static function authorize_url( array $storage, int $user ): string {
		self::require_own( $storage );
		$state    = bin2hex( random_bytes( 16 ) );
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE verifier.
		set_transient(
			'fmwp_gdrive_' . $state,
			array(
				'storage'  => (string) $storage['id'],
				'verifier' => $verifier,
				'user'     => $user,
			),
			self::STATE_TTL
		);
		return self::endpoint( 'auth' ) . '?' . http_build_query(
			array(
				'client_id'              => (string) $storage['client_id'],
				'redirect_uri'           => self::redirect_uri(),
				'response_type'          => 'code',
				'scope'                  => self::SCOPE,
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
				'code_challenge'         => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE challenge.
				'code_challenge_method'  => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Finishes the sign-in: checks the state, trades the code for tokens and saves them.
	 *
	 * @param string $state State from Google.
	 * @param string $code  Authorization code.
	 * @param int    $user  Current user.
	 * @return array<string,mixed> The connected storage.
	 * @throws RemoteException When the state is unknown or Google refuses.
	 */
	public static function finish( string $state, string $code, int $user ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $state ) ) {
			throw new RemoteException( 'The sign-in link is not valid.' );
		}
		$pending = get_transient( 'fmwp_gdrive_' . $state );
		delete_transient( 'fmwp_gdrive_' . $state ); // Single use.
		if ( ! is_array( $pending ) || ( 0 !== (int) $pending['user'] && (int) $pending['user'] !== $user ) ) {
			throw new RemoteException( 'This sign-in expired or was started by someone else. Click Connect again.' );
		}
		$storage = Storages::get( (string) $pending['storage'] );
		if ( null === $storage ) {
			throw new RemoteException( 'The storage was deleted in the meantime.' );
		}
		$tokens = self::token_request(
			$storage,
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::redirect_uri(),
				'code_verifier' => (string) $pending['verifier'],
			)
		);
		if ( empty( $tokens['refresh_token'] ) ) {
			throw new RemoteException( 'Google did not grant offline access. Remove the app\'s access in your Google account (Security > Third-party access) and connect again.' );
		}

		$saved = Storages::store()->change(
			(string) $storage['id'],
			static function ( array $current ) use ( $tokens ): array {
				$current['refresh']      = Secrets::seal( (string) $tokens['refresh_token'] );
				$current['access']       = self::seal_access( $tokens );
				$current['folder_id']    = ''; // Found or created again with the new account.
				$current['account']      = '';
				$current['connected_at'] = time();
				return $current;
			}
		);
		if ( null === $saved ) {
			throw new RemoteException( 'The storage was deleted in the meantime.' );
		}
		try {
			$account = ( new DriveDriver( $saved ) )->account();
		} catch ( RemoteException $e ) {
			return $saved; // Connected; the name of the account is only for display (Test shows what is wrong).
		}
		return (array) Storages::store()->change(
			(string) $storage['id'],
			static function ( array $current ) use ( $account ): array {
				$current['account'] = $account;
				return $current;
			}
		);
	}

	/**
	 * A valid access token, refreshed when needed.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @param bool                $force   Refresh even when the saved token looks valid (after a 401).
	 * @return string
	 * @throws RemoteException When the storage is not connected, or Google refuses the refresh.
	 */
	public static function access_token( array $storage, bool $force = false ): string {
		if ( ! $force ) {
			$saved = json_decode( (string) Secrets::open( $storage['access'] ?? null ), true );
			if ( is_array( $saved ) && ! empty( $saved['token'] ) && (int) ( $saved['expires'] ?? 0 ) > time() + 120 ) {
				return (string) $saved['token'];
			}
		}
		$refresh = Secrets::open( $storage['refresh'] ?? null );
		if ( null === $refresh || '' === $refresh ) {
			throw new RemoteException( sprintf( '"%s" is not connected to a Google account yet (or the site\'s salts changed). Click Connect on the Cloud storage page, or run `wp fmw storage connect %s`.', (string) ( $storage['name'] ?? '' ), (string) ( $storage['id'] ?? '' ) ), 401, 'NotConnected' );
		}
		try {
			$tokens = self::token_request(
				$storage,
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
				)
			);
		} catch ( RemoteException $e ) {
			if ( 'invalid_grant' === $e->error_code ) {
				throw new RemoteException( sprintf( 'Google no longer accepts the sign-in of "%s" (access removed, password changed, or unused for six months). Connect it again.', (string) $storage['name'] ), 401, 'NotConnected' );
			}
			throw $e;
		}
		Storages::store()->change(
			(string) $storage['id'],
			static function ( array $current ) use ( $tokens ): array {
				$current['access'] = self::seal_access( $tokens );
				if ( ! empty( $tokens['refresh_token'] ) ) {
					$current['refresh'] = Secrets::seal( (string) $tokens['refresh_token'] ); // Google may rotate it.
				}
				return $current;
			}
		);
		return (string) $tokens['access_token'];
	}

	/**
	 * Forgets the tokens of a storage (and tells Google, best effort).
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return void
	 */
	public static function disconnect( array $storage ): void {
		$refresh = Secrets::open( $storage['refresh'] ?? null );
		if ( null !== $refresh && '' !== $refresh ) {
			try {
				Http::request( self::endpoint( 'revoke' ), 'POST', array( 'content-type' => 'application/x-www-form-urlencoded' ), array( 'string' => http_build_query( array( 'token' => $refresh ) ) ) );
			} catch ( RemoteException $e ) {
				unset( $e ); // Forgetting the token here is what matters.
			}
		}
		Storages::store()->change(
			(string) $storage['id'],
			static function ( array $current ): array {
				$current['refresh']   = '';
				$current['access']    = '';
				$current['account']   = '';
				$current['folder_id'] = '';
				return $current;
			}
		);
	}

	/**
	 * A request to Google's token endpoint.
	 *
	 * @param array<string,mixed>  $storage Storage.
	 * @param array<string,string> $fields  Grant fields.
	 * @return array<string,mixed>
	 * @throws RemoteException When Google refuses.
	 */
	private static function token_request( array $storage, array $fields ): array {
		self::require_own( $storage );
		$secret = Secrets::open( $storage['secret'] ?? null );
		if ( null === $secret ) {
			throw new RemoteException( sprintf( 'The client secret of "%s" cannot be read (the site\'s salts changed). Enter it again.', (string) $storage['name'] ) );
		}
		$fields  += array(
			'client_id'     => (string) $storage['client_id'],
			'client_secret' => $secret,
		);
		$response = Http::request( self::endpoint( 'token' ), 'POST', array( 'content-type' => 'application/x-www-form-urlencoded' ), array( 'string' => http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ) ) );
		$data     = json_decode( $response['body'], true );
		$data     = is_array( $data ) ? $data : array();
		if ( 200 !== $response['status'] || empty( $data['access_token'] ) ) {
			$error = (string) ( $data['error'] ?? 'unknown_error' );
			$hints = array(
				'invalid_client'        => 'Check the client ID and client secret.',
				'redirect_uri_mismatch' => 'Add the redirect URI shown on the Cloud storage page to the OAuth client in the Google Cloud Console.',
				'invalid_grant'         => 'The code or sign-in is no longer valid; connect again.',
				'unauthorized_client'   => 'The OAuth client must be of type "Web application".',
			);
			throw new RemoteException( trim( sprintf( 'Google sign-in failed (%s): %s %s', $error, (string) ( $data['error_description'] ?? '' ), $hints[ $error ] ?? '' ), " \t\n\r\0\x0B" ), $response['status'], $error );
		}
		return $data;
	}

	/**
	 * Sealed access token with its expiry.
	 *
	 * @param array<string,mixed> $tokens Token response.
	 * @return string
	 */
	private static function seal_access( array $tokens ): string {
		return Secrets::seal(
			(string) wp_json_encode(
				array(
					'token'   => (string) $tokens['access_token'],
					'expires' => time() + (int) ( $tokens['expires_in'] ?? 3600 ),
				)
			)
		);
	}

	/**
	 * Refuses sign-in modes this version cannot do.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return void
	 * @throws RemoteException For another mode.
	 */
	private static function require_own( array $storage ): void {
		if ( 'own' !== (string) ( $storage['auth'] ?? 'own' ) ) {
			throw new RemoteException( 'This Google Drive storage uses a sign-in mode that this version of the plugin does not have. Update the plugin, or edit the storage to use your own OAuth client.' );
		}
	}
}
