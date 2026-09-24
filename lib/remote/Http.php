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

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming uploads and downloads need curl and native file handles.

/**
 * HTTP over curl for the cloud storages: bodies streamed from file ranges,
 * range downloads streamed into files, no redirects followed.
 */
final class Http {

	/**
	 * Sends one request. The HTTP status is returned, not judged (a 308 is fine for Google Drive).
	 *
	 * @param string                                                     $url     URL.
	 * @param string                                                     $method  Method.
	 * @param array<string,string>                                       $headers Headers (name => value).
	 * @param array{string?:string,file?:string,offset?:int,length?:int} $body    Body: a string, or a file range.
	 * @param resource|null                                              $sink    Where a 206 range response goes.
	 * @param int                                                        $limit   Most bytes to accept into $sink.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 * @throws RemoteException On a network error, or a range response that is not what was asked for.
	 */
	public static function request( string $url, string $method, array $headers, array $body = array( 'string' => '' ), $sink = null, int $limit = 0 ): array {
		if ( ! function_exists( 'curl_init' ) ) {
			throw new RemoteException( 'PHP\'s curl extension is needed for cloud storage.' );
		}
		$lines = array( 'Expect:' ); // No 100-continue round trip.
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		$response = array(
			'status'  => 0,
			'headers' => array(),
			'body'    => '',
		);
		$written  = 0;
		$refused  = '';
		$curl     = curl_init( $url );
		$handle   = null;
		$options  = array(
			CURLOPT_CUSTOMREQUEST   => $method,
			CURLOPT_HTTPHEADER      => $lines,
			CURLOPT_CONNECTTIMEOUT  => 20,
			CURLOPT_LOW_SPEED_LIMIT => 1024,
			CURLOPT_LOW_SPEED_TIME  => 120,
			CURLOPT_NOBODY          => 'HEAD' === $method,
			CURLOPT_HEADERFUNCTION  => static function ( $curl, string $line ) use ( &$response ): int {
				if ( 1 === preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $match ) ) {
					$response['status']  = (int) $match[1];
					$response['headers'] = array(); // A new response (after 100 Continue).
				} elseif ( false !== strpos( $line, ':' ) ) {
					list( $name, $value ) = explode( ':', $line, 2 );
					$response['headers'][ strtolower( trim( $name, " \t\n\r\0\x0B" ) ) ] = trim( $value, " \t\n\r\0\x0B" );
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION   => static function ( $curl, string $data ) use ( &$response, &$written, &$refused, $sink, $limit ): int {
				if ( null !== $sink && $response['status'] >= 200 && $response['status'] < 300 ) {
					// Only a 206 with at most the bytes asked for goes into the file (a proxy may ignore the range).
					if ( 206 !== $response['status'] || $written + strlen( $data ) > $limit ) {
						$refused = 206 !== $response['status'] ? 'The storage (or a proxy) ignored the byte range.' : 'The storage sent more bytes than asked for.';
						return 0; // Stops the transfer.
					}
					$written += strlen( $data );
					return (int) fwrite( $sink, $data );
				}
				if ( strlen( $response['body'] ) < 1048576 ) {
					$response['body'] .= $data;
				}
				return strlen( $data );
			},
		);
		if ( isset( $body['file'] ) ) {
			$handle = fopen( (string) $body['file'], 'rb' );
			if ( false === $handle || 0 !== fseek( $handle, (int) $body['offset'] ) ) {
				throw new RemoteException( sprintf( 'Cannot read %s.', $body['file'] ) );
			}
			$left                            = (int) $body['length'];
			$options[ CURLOPT_UPLOAD ]       = true;
			$options[ CURLOPT_INFILESIZE ]   = $left;
			$options[ CURLOPT_READFUNCTION ] = static function ( $curl, $unused, int $size ) use ( $handle, &$left ): string {
				if ( $left <= 0 ) {
					return '';
				}
				$data  = (string) fread( $handle, min( $size, $left ) );
				$left -= strlen( $data );
				return $data;
			};
		} elseif ( in_array( $method, array( 'PUT', 'POST', 'PATCH' ), true ) ) {
			$options[ CURLOPT_POSTFIELDS ] = (string) ( $body['string'] ?? '' );
		}
		/**
		 * Filters the curl options of cloud storage requests (proxy, CA bundle, ...).
		 *
		 * @param array<int,mixed> $options curl options.
		 */
		$options = function_exists( 'apply_filters' ) ? (array) apply_filters( 'fmwp_remote_curl_options', $options ) : $options;
		curl_setopt_array( $curl, $options );

		$ok    = curl_exec( $curl );
		$error = curl_error( $curl );
		$errno = curl_errno( $curl );
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $curl ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated -- Since PHP 8.0 the handle is freed with the object (and curl_close() is deprecated in 8.5).
		}
		if ( null !== $handle ) {
			fclose( $handle );
		}

		if ( '' !== $refused ) {
			throw new RemoteException( $refused, 400, 'InvalidRange' );
		}
		if ( false === $ok || 0 !== $errno ) {
			throw new RemoteException( sprintf( 'Cannot reach the storage: %s', '' !== $error ? $error : 'curl error ' . $errno ), 0 );
		}
		return $response;
	}
}
