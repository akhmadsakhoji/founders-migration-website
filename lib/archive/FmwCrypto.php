<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Archive;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Password encryption of .fmw parts and manifests (format v1, section 7).
 *
 * Each encrypted file is the OpenSSL "enc" format: "Salted__", an 8-byte
 * salt, then AES-256-CBC ciphertext with PKCS#7 padding. PBKDF2-HMAC-SHA256
 * over the password and that salt gives 80 bytes: AES key (0-31), IV (32-47)
 * and HMAC key (48-79). The first 48 bytes are what `openssl enc -pbkdf2
 * -md sha256` derives, so a part can be decrypted with the OpenSSL command
 * line. The HMAC-SHA256 of the whole stored file (encrypt-then-MAC) is kept
 * in the manifest, or in fmw.json for the manifest itself.
 */
final class FmwCrypto {

	const CIPHER     = 'aes-256-cbc';
	const ITERATIONS = 600000;
	const MAGIC      = 'Salted__';
	const SALT_BYTES = 8;
	const HEADER     = 16;
	const BLOCK      = 16;

	/**
	 * Derived keys of this process, by password + salt + iterations.
	 *
	 * @var array<string,array{key:string,iv:string,mac:string}>
	 */
	private static $cache = array();

	/**
	 * Keys for one encrypted file.
	 *
	 * @param string $password   Password.
	 * @param string $salt       8-byte salt.
	 * @param int    $iterations PBKDF2 iterations.
	 * @return array{key:string,iv:string,mac:string}
	 * @throws ArchiveException When OpenSSL is missing.
	 */
	public static function keys( string $password, string $salt, int $iterations ): array {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new ArchiveException( 'PHP\'s OpenSSL extension is needed for encrypted backups.' );
		}
		$id = hash( 'sha256', $iterations . "\0" . $salt . "\0" . $password );
		if ( ! isset( self::$cache[ $id ] ) ) {
			if ( count( self::$cache ) > 64 ) {
				self::$cache = array();
			}
			$bytes              = hash_pbkdf2( 'sha256', $password, $salt, $iterations, 80, true );
			self::$cache[ $id ] = array(
				'key' => substr( $bytes, 0, 32 ),
				'iv'  => substr( $bytes, 32, 16 ),
				'mac' => substr( $bytes, 48, 32 ),
			);
		}
		return self::$cache[ $id ];
	}

	/**
	 * Stored size of a file of $plain bytes: header plus padded ciphertext.
	 *
	 * @param int $plain Plain-text size.
	 * @return int
	 */
	public static function stored_size( int $plain ): int {
		return self::HEADER + $plain - ( $plain % self::BLOCK ) + self::BLOCK;
	}

	/**
	 * Header of a new encrypted file, with a fresh salt.
	 *
	 * @return array{salt:string,header:string}
	 */
	public static function new_header(): array {
		$salt = random_bytes( self::SALT_BYTES );
		return array(
			'salt'   => $salt,
			'header' => self::MAGIC . $salt,
		);
	}

	/**
	 * Salt from a stored file's first 16 bytes.
	 *
	 * @param string $header First 16 bytes.
	 * @return string
	 * @throws ArchiveException When they are not an OpenSSL "Salted__" header.
	 */
	public static function salt( string $header ): string {
		if ( self::HEADER !== strlen( $header ) || self::MAGIC !== substr( $header, 0, 8 ) ) {
			throw new ArchiveException( 'Not an encrypted FMW file (missing "Salted__" header).' );
		}
		return substr( $header, 8, 8 );
	}

	/**
	 * Encrypts a small string (the manifest).
	 *
	 * @param string $plain      Plain text.
	 * @param string $password   Password.
	 * @param int    $iterations PBKDF2 iterations.
	 * @return array{data:string,hmac:string} Stored bytes and their HMAC (hex).
	 */
	public static function encrypt_string( string $plain, string $password, int $iterations ): array {
		$new    = self::new_header();
		$keys   = self::keys( $password, $new['salt'], $iterations );
		$cipher = new CbcStream( $keys['key'], $keys['iv'], true );
		$data   = $new['header'] . $cipher->finish( $plain );
		return array(
			'data' => $data,
			'hmac' => hash_hmac( 'sha256', $data, $keys['mac'] ),
		);
	}

	/**
	 * Checks the HMAC, then decrypts a small string.
	 *
	 * @param string $data       Stored bytes.
	 * @param string $password   Password.
	 * @param int    $iterations PBKDF2 iterations.
	 * @param string $hmac       Expected HMAC (hex).
	 * @return string
	 * @throws ArchiveException On a wrong password or damaged data.
	 */
	public static function decrypt_string( string $data, string $password, int $iterations, string $hmac ): string {
		$keys = self::keys( $password, self::salt( substr( $data, 0, self::HEADER ) ), $iterations );
		if ( ! hash_equals( strtolower( $hmac ), hash_hmac( 'sha256', $data, $keys['mac'] ) ) ) {
			throw new ArchiveException( 'Wrong password, or the backup is damaged.' );
		}
		$cipher = new CbcStream( $keys['key'], $keys['iv'], false );
		return $cipher->finish( (string) substr( $data, self::HEADER ) );
	}
}
