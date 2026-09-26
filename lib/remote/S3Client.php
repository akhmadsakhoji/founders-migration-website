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

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming uploads and downloads need curl and native file handles.

/**
 * Minimal S3 client (AWS S3, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO, ...).
 *
 * Pure PHP on top of the curl extension, no SDK. Files are streamed from
 * and to disk in ranges, so a part of any size never has to fit in memory,
 * and every upload is signed with the SHA-256 of its bytes, so the server
 * refuses a part that was damaged on the way.
 */
final class S3Client {

	const MIN_PART  = 5242880;     // 5 MiB: smallest part S3 accepts (except the last).
	const MAX_PARTS = 10000;
	const RETRIES   = 3;

	/**
	 * Connection settings: endpoint, region, bucket, access_key, secret_key, path_style.
	 *
	 * @var array<string,mixed>
	 */
	private $config;

	/**
	 * Waits between retries (tests replace it).
	 *
	 * @var callable(int): void
	 */
	private $sleep;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $config endpoint (https://host[:port][/path]), region, bucket, access_key, secret_key, path_style.
	 * @throws RemoteException When the curl extension is missing or the endpoint is not a URL.
	 */
	public function __construct( array $config ) {
		if ( ! function_exists( 'curl_init' ) ) {
			throw new RemoteException( 'PHP\'s curl extension is needed for cloud storage.' );
		}
		$parts = parse_url( (string) ( $config['endpoint'] ?? '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Also used without WordPress.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			throw new RemoteException( 'The storage endpoint must be a URL such as https://s3.eu-central-1.amazonaws.com.' );
		}
		$this->config = $config + array(
			'region'     => 'us-east-1',
			'path_style' => true,
		);
		$this->sleep  = static function ( int $seconds ): void {
			sleep( $seconds );
		};
	}

	/**
	 * Replaces the wait between retries.
	 *
	 * @param callable(int): void $sleep Sleeper.
	 * @return void
	 */
	public function set_sleep( callable $sleep ): void {
		$this->sleep = $sleep;
	}

	/**
	 * Uploads a small string (connection test).
	 *
	 * @param string               $key     Object key.
	 * @param string               $body    Body.
	 * @param array<string,string> $headers Extra headers.
	 * @return void
	 */
	public function put_string( string $key, string $body, array $headers = array() ): void {
		$this->send( 'PUT', $key, array(), $headers, array( 'string' => $body ) );
	}

	/**
	 * Uploads a range of a file as one object.
	 *
	 * @param string               $key     Object key.
	 * @param string               $path    File.
	 * @param int                  $offset  First byte.
	 * @param int                  $length  Bytes.
	 * @param array<string,string> $headers Extra headers (x-amz-storage-class, ...).
	 * @return void
	 */
	public function put_file( string $key, string $path, int $offset, int $length, array $headers = array() ): void {
		$this->send( 'PUT', $key, array(), $headers, self::file_body( $path, $offset, $length ) );
	}

	/**
	 * Starts a multipart upload.
	 *
	 * @param string               $key     Object key.
	 * @param array<string,string> $headers Extra headers (x-amz-storage-class, ...).
	 * @return string Upload ID.
	 * @throws RemoteException When the answer has no upload ID.
	 */
	public function create_multipart( string $key, array $headers = array() ): string {
		$response = $this->send( 'POST', $key, array( 'uploads' => '' ), $headers, array( 'string' => '' ) );
		$id       = self::xml_value( $response['body'], 'UploadId' );
		if ( '' === $id ) {
			throw new RemoteException( 'The storage did not start the multipart upload (no UploadId).' );
		}
		return $id;
	}

	/**
	 * Uploads one part of a multipart upload.
	 *
	 * @param string $key    Object key.
	 * @param string $upload Upload ID.
	 * @param int    $number Part number, 1 to 10000.
	 * @param string $path   File.
	 * @param int    $offset First byte.
	 * @param int    $length Bytes.
	 * @return string ETag of the part.
	 * @throws RemoteException When the answer has no ETag.
	 */
	public function upload_part( string $key, string $upload, int $number, string $path, int $offset, int $length ): string {
		$response = $this->send(
			'PUT',
			$key,
			array(
				'partNumber' => (string) $number,
				'uploadId'   => $upload,
			),
			array(),
			self::file_body( $path, $offset, $length )
		);
		$etag     = (string) ( $response['headers']['etag'] ?? '' );
		if ( '' === $etag ) {
			throw new RemoteException( sprintf( 'The storage returned no ETag for part %d.', $number ) );
		}
		return $etag;
	}

	/**
	 * Completes a multipart upload.
	 *
	 * @param string            $key    Object key.
	 * @param string            $upload Upload ID.
	 * @param array<int,string> $parts  ETags by part number.
	 * @return void
	 * @throws RemoteException When the storage refuses (it can answer 200 with an error inside).
	 */
	public function complete_multipart( string $key, string $upload, array $parts ): void {
		ksort( $parts );
		$xml = '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		foreach ( $parts as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars( $etag, ENT_XML1 ) . '</ETag></Part>';
		}
		$xml     .= '</CompleteMultipartUpload>';
		$response = $this->send( 'POST', $key, array( 'uploadId' => $upload ), array( 'content-type' => 'application/xml' ), array( 'string' => $xml ) );
		if ( false !== strpos( $response['body'], '<Error>' ) ) {
			throw new RemoteException( ...self::error( 200, $response['body'] ) );
		}
	}

	/**
	 * Abandons a multipart upload (its parts are deleted by the storage).
	 *
	 * @param string $key    Object key.
	 * @param string $upload Upload ID.
	 * @return void
	 * @throws RemoteException When the storage refuses (other than "not found").
	 */
	public function abort_multipart( string $key, string $upload ): void {
		try {
			$this->send( 'DELETE', $key, array( 'uploadId' => $upload ), array(), array( 'string' => '' ) );
		} catch ( RemoteException $e ) {
			if ( 404 !== $e->status ) {
				throw $e;
			}
		}
	}

	/**
	 * Size and date of an object, or null when it does not exist.
	 *
	 * @param string $key Object key.
	 * @return array{size:int,mtime:int,etag:string}|null
	 * @throws RemoteException When the storage refuses (other than "not found").
	 */
	public function head( string $key ): ?array {
		try {
			$response = $this->send( 'HEAD', $key, array(), array(), array( 'string' => '' ) );
		} catch ( RemoteException $e ) {
			if ( 404 === $e->status ) {
				return null;
			}
			throw $e;
		}
		return array(
			'size'  => (int) ( $response['headers']['content-length'] ?? 0 ),
			'mtime' => (int) strtotime( (string) ( $response['headers']['last-modified'] ?? '' ) ),
			'etag'  => trim( (string) ( $response['headers']['etag'] ?? '' ), '"' ),
		);
	}

	/**
	 * Downloads bytes $from..$to (inclusive) of an object into an open file.
	 *
	 * @param string   $key  Object key.
	 * @param int      $from First byte.
	 * @param int      $to   Last byte.
	 * @param resource $sink Writable handle, positioned where the bytes go.
	 * @param string   $etag Only if the object still has this ETag ('' for any).
	 * @return int Bytes written.
	 * @throws RemoteException When the answer is not the range asked for, or the object changed.
	 */
	public function get_range( string $key, int $from, int $to, $sink, string $etag = '' ): int {
		$start   = (int) ftell( $sink );
		$headers = array( 'range' => 'bytes=' . $from . '-' . $to );
		if ( '' !== $etag ) {
			$headers['if-match'] = '"' . trim( $etag, '"' ) . '"';
		}
		try {
			$response = $this->send( 'GET', $key, array(), $headers, array( 'string' => '' ), $sink, $to - $from + 1 );
		} catch ( RemoteException $e ) {
			if ( 412 === $e->status ) {
				throw new RemoteException( sprintf( '%s was replaced in the storage during the download; start the download again.', $key ), 412, 'PreconditionFailed' );
			}
			throw $e;
		}
		$written = (int) ftell( $sink ) - $start;
		if ( $written !== $to - $from + 1 ) {
			throw new RemoteException( sprintf( 'Download of %s stopped after %d of %d bytes (HTTP %d).', $key, $written, $to - $from + 1, $response['status'] ), 0 );
		}
		return $written;
	}

	/**
	 * Downloads a small object.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public function get_string( string $key ): string {
		return $this->send( 'GET', $key, array(), array(), array( 'string' => '' ) )['body'];
	}

	/**
	 * Deletes an object (no error when it is already gone).
	 *
	 * @param string $key Object key.
	 * @return void
	 * @throws RemoteException When the storage refuses.
	 */
	public function delete( string $key ): void {
		try {
			$this->send( 'DELETE', $key, array(), array(), array( 'string' => '' ) );
		} catch ( RemoteException $e ) {
			if ( 404 !== $e->status ) {
				throw $e;
			}
		}
	}

	/**
	 * Objects under a prefix (not recursing into "folders").
	 *
	 * @param string $prefix Prefix, such as "backups/" ('' for the bucket root).
	 * @param int    $limit  Stop after this many objects.
	 * @return array<int,array{key:string,size:int,mtime:int}>
	 */
	public function list( string $prefix, int $limit = 10000 ): array {
		$objects = array();
		$token   = '';
		do {
			$query = array(
				'list-type' => '2',
				'prefix'    => $prefix,
				'delimiter' => '/',
				'max-keys'  => '1000',
			);
			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}
			$body = $this->send( 'GET', '', $query, array(), array( 'string' => '' ) )['body'];
			if ( preg_match_all( '#<Contents>(.*?)</Contents>#s', $body, $matches ) ) {
				foreach ( $matches[1] as $item ) {
					$objects[] = array(
						'key'   => self::xml_value( $item, 'Key' ),
						'size'  => (int) self::xml_value( $item, 'Size' ),
						'mtime' => (int) strtotime( self::xml_value( $item, 'LastModified' ) ),
					);
				}
			}
			$token = 'true' === self::xml_value( $body, 'IsTruncated' ) ? self::xml_value( $body, 'NextContinuationToken' ) : '';
			$count = count( $objects );
		} while ( '' !== $token && $count < $limit );
		return $objects;
	}

	/**
	 * Sends a request, trying again after network trouble or a server error.
	 *
	 * @param string                                                                  $method  Method.
	 * @param string                                                                  $key     Object key ('' for the bucket).
	 * @param array<string,string>                                                    $query   Query.
	 * @param array<string,string>                                                    $headers Headers.
	 * @param array{string?:string,file?:string,offset?:int,length?:int,hash?:string} $body    Body.
	 * @param resource|null                                                           $sink    Where a successful (206) range response goes.
	 * @param int                                                                     $limit   Most bytes to accept into $sink.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 * @throws RemoteException When it fails for good.
	 */
	private function send( string $method, string $key, array $query, array $headers, array $body, $sink = null, int $limit = 0 ): array {
		$start = null === $sink ? 0 : (int) ftell( $sink );
		for ( $attempt = 1; ; $attempt++ ) {
			try {
				return $this->request( $method, $key, $query, $headers, $body, $sink, $limit );
			} catch ( RemoteException $e ) {
				if ( $attempt >= self::RETRIES || ! $e->is_transient() ) {
					throw $e;
				}
				if ( null !== $sink ) {
					ftruncate( $sink, $start );
					fseek( $sink, $start );
				}
				( $this->sleep )( 2 ** ( $attempt - 1 ) );
			}
		}
	}

	/**
	 * One HTTP request.
	 *
	 * @param string                                                                  $method  Method.
	 * @param string                                                                  $key     Object key.
	 * @param array<string,string>                                                    $query   Query.
	 * @param array<string,string>                                                    $headers Headers.
	 * @param array{string?:string,file?:string,offset?:int,length?:int,hash?:string} $body    Body.
	 * @param resource|null                                                           $sink    Download target (range requests).
	 * @param int                                                                     $limit   Most bytes to accept into $sink.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 * @throws RemoteException On a network error or an error status.
	 */
	private function request( string $method, string $key, array $query, array $headers, array $body, $sink, int $limit = 0 ): array {
		$target = $this->target( $key, $query );
		$hash   = isset( $body['file'] ) ? (string) ( $body['hash'] ?? '' ) : hash( 'sha256', (string) ( $body['string'] ?? '' ) );
		$signed = SigV4::sign(
			$method,
			$target['host'],
			$target['path'],
			$query,
			$headers,
			$hash,
			array(
				'region'     => (string) $this->config['region'],
				'access_key' => (string) $this->config['access_key'],
				'secret_key' => (string) $this->config['secret_key'],
			),
			time()
		);
		unset( $signed['host'] );

		$response = Http::request( $target['url'], $method, $signed, $body, $sink, $limit );
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			throw new RemoteException( ...self::error( $response['status'], $response['body'] ) );
		}
		return $response;
	}

	/**
	 * URL, Host header and signed path of a request.
	 *
	 * @param string               $key   Object key.
	 * @param array<string,string> $query Query.
	 * @return array{url:string,host:string,path:string}
	 */
	private function target( string $key, array $query ): array {
		$parts = (array) parse_url( (string) $this->config['endpoint'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Also used without WordPress.
		$host  = strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$base  = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$key   = ltrim( $key, '/' );
		if ( ! empty( $this->config['path_style'] ) ) {
			$path = $base . '/' . $this->config['bucket'] . ( '' !== $key ? '/' . $key : '' );
		} else {
			$host = $this->config['bucket'] . '.' . $host;
			$path = $base . '/' . $key;
		}
		$path = SigV4::encode_path( $path );
		$url  = $parts['scheme'] . '://' . $host . $path;
		if ( $query ) {
			$url .= '?' . SigV4::canonical_query( $query );
		}
		return array(
			'url'  => $url,
			'host' => $host,
			'path' => $path,
		);
	}

	/**
	 * Body that streams a file range, with its SHA-256.
	 *
	 * @param string $path   File.
	 * @param int    $offset First byte.
	 * @param int    $length Bytes.
	 * @return array{file:string,offset:int,length:int,hash:string}
	 * @throws RemoteException When the range cannot be read.
	 */
	private static function file_body( string $path, int $offset, int $length ): array {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, $offset ) ) {
			throw new RemoteException( sprintf( 'Cannot read %s.', $path ) );
		}
		$context = hash_init( 'sha256' );
		$left    = $length;
		while ( $left > 0 && ! feof( $handle ) ) {
			$data = (string) fread( $handle, (int) min( 1048576, $left ) );
			if ( '' === $data ) {
				break;
			}
			hash_update( $context, $data );
			$left -= strlen( $data );
		}
		fclose( $handle );
		if ( 0 !== $left ) {
			throw new RemoteException( sprintf( '%s is shorter than expected.', $path ) );
		}
		return array(
			'file'   => $path,
			'offset' => $offset,
			'length' => $length,
			'hash'   => hash_final( $context ),
		);
	}

	/**
	 * Message, status and code for an S3 error response (RemoteException arguments).
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   XML body.
	 * @return array{0:string,1:int,2:string}
	 */
	private static function error( int $status, string $body ): array {
		$code    = self::xml_value( $body, 'Code' );
		$message = self::xml_value( $body, 'Message' );
		$hints   = array(
			'SignatureDoesNotMatch' => 'Check the secret key and the region.',
			'InvalidAccessKeyId'    => 'Check the access key.',
			'AccessDenied'          => 'The key is not allowed to do this; it needs list, read, write and delete on the bucket.',
			'NoSuchBucket'          => 'Check the bucket name, the region and the endpoint.',
			'RequestTimeTooSkewed'  => 'The server clock is wrong; fix its time (NTP).',
			'PermanentRedirect'     => 'The bucket is in another region: use its regional endpoint.',
		);
		$text    = sprintf( 'Storage error %d%s%s', $status, '' !== $code ? ' ' . $code : '', '' !== $message ? ': ' . $message : '' );
		if ( isset( $hints[ $code ] ) ) {
			$text .= ' ' . $hints[ $code ];
		}
		return array( rtrim( $text, '.' ) . '.', $status, $code );
	}

	/**
	 * First value of an XML element (S3 answers are simple and flat enough for this).
	 *
	 * @param string $xml  XML.
	 * @param string $name Element.
	 * @return string
	 */
	public static function xml_value( string $xml, string $name ): string {
		if ( 1 !== preg_match( '#<' . $name . '>(.*?)</' . $name . '>#s', $xml, $match ) ) {
			return '';
		}
		return html_entity_decode( $match[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
