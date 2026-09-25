<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Pull;

use Founders\Migration\Remote\Http;
use Founders\Migration\Remote\RemoteException;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Talks to the source site's pull API (docs/pull-v1.md).
 *
 * Requests go to <site>/?rest_route=/fmw/v1/pull/..., which works with and
 * without pretty permalinks. HTTPS is required (with certificate checks)
 * except for local addresses, or when explicitly allowed.
 */
final class PullClient {

	const PROTOCOL = 1;
	const RETRIES  = 4;
	const TIMEOUT  = 300; // Seconds for an API call (a backup slice on the source takes about 20).

	/**
	 * Site address, without a trailing slash.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Pull key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Sleep function (replaced in tests).
	 *
	 * @var callable(int): void
	 */
	private $sleep;

	/**
	 * Constructor.
	 *
	 * @param string        $url        Source site address.
	 * @param string        $key        Pull key.
	 * @param bool          $allow_http Allow plain HTTP to non-local addresses.
	 * @param callable|null $sleep      Sleep function.
	 * @throws PullException When the address is not usable.
	 */
	public function __construct( string $url, string $key, bool $allow_http = false, ?callable $sleep = null ) {
		$this->url   = self::normalize( $url, $allow_http );
		$this->key   = trim( $key, " \t\n\r\0\x0B" );
		$this->sleep = $sleep ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
		if ( 1 !== preg_match( '/^' . PullKeys::PREFIX . '[0-9a-f]{8}_[A-Za-z0-9_-]{43}$/', $this->key ) ) {
			throw new PullException( 'That is not a pull key. Create one on the source site with `wp fmw pull-key create` (it starts with fmwpk_).', 0, 'fmw_pull_key_format' );
		}
	}

	/**
	 * The normalized site address.
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Checks an address and returns it without query, fragment or trailing slash.
	 *
	 * @param string $url        Address as typed.
	 * @param bool   $allow_http Allow plain HTTP to any host.
	 * @return string
	 * @throws PullException When it is not an http(s) address, or plain HTTP to a remote host.
	 */
	public static function normalize( string $url, bool $allow_http = false ): string {
		$url = trim( $url, " \t\n\r\0\x0B" );
		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$parts  = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Outside WordPress in tests.
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new PullException( sprintf( '"%s" is not a website address.', $url ), 0, 'fmw_pull_url' );
		}
		if ( 'http' === $scheme && ! $allow_http && ! self::is_local( $host ) ) {
			throw new PullException( 'The source site must be reached over HTTPS, so the pull key and the backup are encrypted on the way. Use https://, or pass --allow-http on a trusted network.', 0, 'fmw_pull_http' );
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Whether a host is this machine or a local development name.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_local( string $host ): bool {
		$host = trim( $host, '[]' );
		if ( in_array( $host, array( 'localhost', '::1' ), true ) || 1 === preg_match( '/\.(localhost|local|test)$/', $host ) ) {
			return true;
		}
		// 127.0.0.0/8, as an address: a name such as 127.example.com is not local.
		return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && 0 === strpos( $host, '127.' );
	}

	/**
	 * Attempts and time limit of API calls (quick() for an interactive check).
	 *
	 * @var array{0:int,1:int}
	 */
	private $limits = array( self::RETRIES, self::TIMEOUT );

	/**
	 * Fails fast: one attempt of at most 30 seconds (for a check in the browser).
	 *
	 * @return self
	 */
	public function quick(): self {
		$this->limits = array( 1, 30 );
		return $this;
	}

	/**
	 * GET /pull: checks the key and the other site's version.
	 *
	 * @return array<string,mixed>
	 * @throws PullException When the site does not answer as expected.
	 */
	public function info(): array {
		$info = $this->json( 'GET', '', array(), $this->limits[0] );
		if ( (int) ( $info['protocol'] ?? 0 ) !== self::PROTOCOL ) {
			throw new PullException( sprintf( 'The source site speaks pull protocol %s; this site speaks %d. Update Founders Migration Website on both sites to the same version.', (string) ( $info['protocol'] ?? '?' ), self::PROTOCOL ), 0, 'fmw_pull_protocol' );
		}
		return $info;
	}

	/**
	 * POST /pull/backups: starts a backup on the source.
	 *
	 * @param array<string,mixed> $flags    Backup flags (exclusions, part size).
	 * @param string              $password Encrypt the backup ('' for none).
	 * @return array<string,mixed> Job summary.
	 * @throws PullException When it cannot start.
	 */
	public function start_backup( array $flags, string $password ): array {
		$body = array( 'flags' => (object) $flags );
		if ( '' !== $password ) {
			$body['password'] = $password;
		}
		return $this->json( 'POST', '/backups', $body, 1 ); // Sent once: a retry could start a second backup.
	}

	/**
	 * POST /pull/jobs/<id>/run: one slice of the source's backup.
	 *
	 * @param string $id Job id.
	 * @return array<string,mixed> Job summary.
	 * @throws PullException On failure.
	 */
	public function run( string $id ): array {
		return $this->json( 'POST', '/jobs/' . rawurlencode( $id ) . '/run' );
	}

	/**
	 * POST /pull/jobs/<id>/cancel.
	 *
	 * @param string $id Job id.
	 * @return array<string,mixed>
	 * @throws PullException On failure.
	 */
	public function cancel( string $id ): array {
		return $this->json( 'POST', '/jobs/' . rawurlencode( $id ) . '/cancel', array(), 2 );
	}

	/**
	 * GET /pull/backups/<name>: size and version tag.
	 *
	 * @param string $name Backup name.
	 * @return array{name:string,size:int,etag:string}
	 * @throws PullException On failure.
	 */
	public function backup( string $name ): array {
		$data = $this->json( 'GET', '/backups/' . rawurlencode( $name ) );
		return array(
			'name' => (string) ( $data['name'] ?? $name ),
			'size' => (int) ( $data['size'] ?? -1 ),
			'etag' => (string) ( $data['etag'] ?? '' ),
		);
	}

	/**
	 * DELETE /pull/backups/<name>.
	 *
	 * @param string $name Backup name.
	 * @return bool Whether it was deleted.
	 * @throws PullException On failure.
	 */
	public function delete( string $name ): bool {
		return ! empty( $this->json( 'DELETE', '/backups/' . rawurlencode( $name ), array(), 2 )['deleted'] );
	}

	/**
	 * Downloads bytes $from..$to of a backup into an open file.
	 *
	 * @param string   $name Backup name.
	 * @param int      $from First byte.
	 * @param int      $to   Last byte.
	 * @param resource $sink Writable handle at the right position.
	 * @param string   $etag Version tag seen when the download started.
	 * @return int Bytes written.
	 * @throws PullException When the range does not arrive whole.
	 */
	public function download_range( string $name, int $from, int $to, $sink, string $etag ): int {
		$start = (int) ftell( $sink );
		for ( $attempt = 1; ; $attempt++ ) {
			$headers = $this->headers() + array( 'Range' => 'bytes=' . $from . '-' . $to );
			if ( '' !== $etag ) {
				$headers['If-Match'] = $etag;
			}
			try {
				// Time limit: at least 256 KiB/s on average, so a trickling source cannot hold the pull forever.
				$response = Http::request( $this->endpoint( '/backups/' . rawurlencode( $name ) . '/file' ), 'GET', $headers, array( 'string' => '' ), $sink, $to - $from + 1, 120 + intdiv( $to - $from + 1, 262144 ) );
				$error    = 206 === $response['status'] ? null : self::error( $response );
			} catch ( RemoteException $e ) {
				$error = new PullException( 'InvalidRange' === $e->error_code ? 'The source site (or a proxy in between) did not send the byte range asked for.' : sprintf( 'Cannot reach %s: %s', $this->url, str_replace( 'Cannot reach the storage: ', '', $e->getMessage() ) ), 'InvalidRange' === $e->error_code ? 400 : 0 );
			}
			$written = (int) ftell( $sink ) - $start;
			if ( null === $error && $written === $to - $from + 1 ) {
				return $written;
			}
			if ( null === $error ) {
				$error = new PullException( sprintf( 'The download stopped after %d of %d bytes.', $written, $to - $from + 1 ), 0 );
			}
			if ( ! $error->is_transient() || $attempt >= self::RETRIES ) {
				throw $error;
			}
			ftruncate( $sink, $start );
			fseek( $sink, $start );
			( $this->sleep )( 2 ** $attempt );
		}
	}

	/**
	 * A JSON request, retried after network trouble and server errors.
	 *
	 * @param string              $method Method.
	 * @param string              $path   Path under /fmw/v1/pull.
	 * @param array<string,mixed> $body   JSON body (POST).
	 * @param int                 $tries  Attempts.
	 * @return array<string,mixed>
	 * @throws PullException When it fails for good.
	 */
	private function json( string $method, string $path, array $body = array(), int $tries = self::RETRIES ): array {
		$headers = $this->headers();
		$payload = array( 'string' => '' );
		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/json';
			$payload                 = array( 'string' => (string) wp_json_encode( (object) $body ) );
		}
		for ( $attempt = 1; ; $attempt++ ) {
			try {
				$response = Http::request( $this->endpoint( $path ), $method, $headers, $payload, null, 0, $this->limits[1] );
				$data     = json_decode( $response['body'], true );
				if ( $response['status'] >= 200 && $response['status'] < 300 && is_array( $data ) ) {
					return $data;
				}
				$error = $response['status'] >= 200 && $response['status'] < 300 ? new PullException( self::not_fmw( $response ), $response['status'], 'fmw_pull_not_json' ) : self::error( $response );
			} catch ( RemoteException $e ) {
				$error = new PullException( sprintf( 'Cannot reach %s: %s', $this->url, str_replace( 'Cannot reach the storage: ', '', $e->getMessage() ) ), 0, 'fmw_pull_network' );
			}
			// A busy source (another backup running) does not get better within seconds: stop.
			if ( ! $error->is_transient() || 409 === $error->status || 429 === $error->status || $attempt >= $tries ) {
				throw new PullException( $error->getMessage(), $error->status, $error->error_code );
			}
			( $this->sleep )( 2 ** $attempt );
		}
	}

	/**
	 * Full URL of a pull route.
	 *
	 * @param string $path Path under /fmw/v1/pull.
	 * @return string
	 */
	private function endpoint( string $path ): string {
		return $this->url . '/?rest_route=' . str_replace( '%2F', '/', rawurlencode( '/fmw/v1/pull' . $path ) );
	}

	/**
	 * Headers of every request.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return array(
			'X-FMW-Pull-Key' => $this->key,
			'Accept'         => 'application/json',
			'User-Agent'     => 'FoundersMigrationWebsite/' . ( defined( 'FMWP_VERSION' ) ? FMWP_VERSION : 'dev' ) . ' (pull)',
		);
	}

	/**
	 * Turns an error response into a message people can act on.
	 *
	 * @param array{status:int,headers:array<string,string>,body:string} $response Response.
	 * @return PullException
	 */
	private static function error( array $response ): PullException {
		$status = $response['status'];
		$data   = json_decode( $response['body'], true );
		if ( in_array( $status, array( 301, 302, 307, 308 ), true ) ) {
			$to = (string) ( $response['headers']['location'] ?? '' );
			if ( false !== strpos( $to, 'wp-login.php' ) ) {
				return new PullException( 'That address leads to the WordPress login. Give the site\'s home address (for example https://example.com), not an admin page.', $status, 'fmw_pull_redirect' );
			}
			$base = (string) preg_replace( '#/?\?rest_route=.*$#', '', $to );
			return new PullException( sprintf( 'The source site redirects to %s. Use that address instead.', '' !== $base ? $base : 'another address' ), $status, 'fmw_pull_redirect' );
		}
		if ( ! is_array( $data ) || ! isset( $data['code'] ) ) {
			return new PullException( self::not_fmw( $response ), $status, 'fmw_pull_not_json' );
		}
		$code    = (string) $data['code'];
		$message = (string) ( $data['message'] ?? 'HTTP ' . $status );
		if ( 'rest_no_route' === $code ) {
			$message = 'The source site does not offer pulls: activate Founders Migration Website there (the same version as here), and make sure FMWP_DISABLE_PULL is not set.';
		}
		return new PullException( $message, $status, $code );
	}

	/**
	 * Message for an answer that is not from FMW's API.
	 *
	 * @param array{status:int,headers:array<string,string>,body:string} $response Response.
	 * @return string
	 */
	private static function not_fmw( array $response ): string {
		return sprintf( 'The source site did not answer with the FMW API (HTTP %d, %s). A security plugin, firewall or maintenance page may block ?rest_route=/fmw/v1/pull; allow it, or check the address.', $response['status'], (string) ( $response['headers']['content-type'] ?? 'no content type' ) );
	}
}
