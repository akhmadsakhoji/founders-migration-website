<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Passwords and keys a job needs across processes, kept sealed in its state.
 *
 * A backup or restore with a password must survive a closed tab or a
 * timeout without asking again, so the secret is stored with the job, but
 * encrypted (AES-256-GCM) with a key derived from the site's AUTH salt in
 * wp-config.php. A copy of the job folder alone reveals nothing. Job options
 * whose name starts with "secret_" hold sealed values; the runner removes
 * them when the job completes or is cancelled.
 */
final class Secrets {

	const PREFIX = 'secret_';

	/**
	 * Seals a value.
	 *
	 * @param string $plain Secret.
	 * @return string Printable sealed form.
	 * @throws JobException When OpenSSL is missing.
	 */
	public static function seal( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new JobException( 'PHP\'s OpenSSL extension is needed for passwords.' );
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			throw new JobException( 'Cannot seal the secret.' );
		}
		return 'v1:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text.
	}

	/**
	 * Opens a sealed value.
	 *
	 * @param mixed $sealed Value from seal().
	 * @return string|null Null when missing or when the site's salts changed since.
	 */
	public static function open( $sealed ): ?string {
		if ( ! is_string( $sealed ) || 0 !== strpos( $sealed, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.
		if ( false === $raw || strlen( $raw ) < 28 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? null : $plain;
	}

	/**
	 * Removes every sealed secret from a job's options.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public static function forget( Job $job ): void {
		foreach ( array_keys( $job->options ) as $name ) {
			if ( 0 === strpos( (string) $name, self::PREFIX ) ) {
				unset( $job->options[ $name ] );
			}
		}
	}

	/**
	 * Sealing key: from wp-config.php's AUTH salt, never stored with the job.
	 *
	 * @return string
	 * @throws JobException Outside WordPress (except in the test suite).
	 */
	private static function key(): string {
		if ( function_exists( 'wp_salt' ) ) {
			$material = wp_salt( 'auth' );
		} elseif ( defined( 'FMWP_TESTS' ) ) {
			$material = 'fmw-tests-without-wordpress';
		} else {
			throw new JobException( 'Job secrets need WordPress (wp_salt).' );
		}
		return hash_hmac( 'sha256', 'fmw-job-secrets', $material, true );
	}
}
