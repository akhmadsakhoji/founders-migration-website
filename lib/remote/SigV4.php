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

/**
 * AWS Signature Version 4 for S3 and S3-compatible services.
 *
 * The payload hash is always the real SHA-256 of the body, so the server
 * rejects a part that was damaged on the way.
 */
final class SigV4 {

	const ALGORITHM   = 'AWS4-HMAC-SHA256';
	const EMPTY_HASH  = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
	const TIME_FORMAT = 'Ymd\THis\Z';

	/**
	 * Headers to send, Authorization included.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $host         Host header value (with the port when not the default).
	 * @param string               $path         URI path, already encoded (see encode_path()).
	 * @param array<string,string> $query        Query parameters (not encoded).
	 * @param array<string,string> $headers      Other headers to sign (x-amz-*, content-type, range, ...).
	 * @param string               $payload_hash Hex SHA-256 of the body.
	 * @param array<string,string> $credentials  region, access_key, secret_key.
	 * @param int                  $time         Unix time of the request.
	 * @return array<string,string>
	 */
	public static function sign( string $method, string $host, string $path, array $query, array $headers, string $payload_hash, array $credentials, int $time ): array {
		$date  = gmdate( 'Ymd', $time );
		$stamp = gmdate( self::TIME_FORMAT, $time );
		$scope = $date . '/' . $credentials['region'] . '/s3/aws4_request';

		$all = array();
		foreach ( $headers as $name => $value ) {
			$all[ strtolower( trim( $name, " \t\n\r\0\x0B" ) ) ] = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ), " \t\n\r\0\x0B" );
		}
		$all['host']                 = $host;
		$all['x-amz-date']           = $stamp;
		$all['x-amz-content-sha256'] = $payload_hash;
		ksort( $all, SORT_STRING );

		$canonical_headers = '';
		foreach ( $all as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed = implode( ';', array_keys( $all ) );

		$request        = implode(
			"\n",
			array(
				strtoupper( $method ),
				'' === $path ? '/' : $path,
				self::canonical_query( $query ),
				$canonical_headers,
				$signed,
				$payload_hash,
			)
		);
		$string_to_sign = implode( "\n", array( self::ALGORITHM, $stamp, $scope, hash( 'sha256', $request ) ) );

		$key = hash_hmac( 'sha256', $date, 'AWS4' . $credentials['secret_key'], true );
		$key = hash_hmac( 'sha256', $credentials['region'], $key, true );
		$key = hash_hmac( 'sha256', 's3', $key, true );
		$key = hash_hmac( 'sha256', 'aws4_request', $key, true );

		$all['authorization'] = sprintf(
			'%s Credential=%s/%s,SignedHeaders=%s,Signature=%s',
			self::ALGORITHM,
			$credentials['access_key'],
			$scope,
			$signed,
			hash_hmac( 'sha256', $string_to_sign, $key )
		);
		return $all;
	}

	/**
	 * Canonical query string: sorted by name, RFC 3986 encoded.
	 *
	 * @param array<string,string> $query Parameters.
	 * @return string
	 */
	public static function canonical_query( array $query ): string {
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[ rawurlencode( (string) $name ) ] = rawurlencode( (string) $value );
		}
		ksort( $pairs, SORT_STRING );
		$parts = array();
		foreach ( $pairs as $name => $value ) {
			$parts[] = $name . '=' . $value;
		}
		return implode( '&', $parts );
	}

	/**
	 * Encodes an object path for the URL and the signature: each segment RFC 3986, slashes kept.
	 *
	 * @param string $path Path such as "/bucket/folder/file name.fmw".
	 * @return string
	 */
	public static function encode_path( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}
}
