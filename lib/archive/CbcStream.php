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

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-CBC over a stream of chunks, with PKCS#7 padding at the end.
 *
 * The update() method takes whole 16-byte blocks; the chaining block (iv()) is all the
 * state there is, so a job can save it and continue in another process.
 */
final class CbcStream {

	/**
	 * AES key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Current chaining block.
	 *
	 * @var string
	 */
	private $iv;

	/**
	 * Encrypting (true) or decrypting.
	 *
	 * @var bool
	 */
	private $encrypt;

	/**
	 * Constructor.
	 *
	 * @param string $key     32-byte key.
	 * @param string $iv      16-byte IV, or the saved chaining block when resuming.
	 * @param bool   $encrypt Direction.
	 */
	public function __construct( string $key, string $iv, bool $encrypt ) {
		$this->key     = $key;
		$this->iv      = $iv;
		$this->encrypt = $encrypt;
	}

	/**
	 * Current chaining block (save it to resume).
	 *
	 * @return string
	 */
	public function iv(): string {
		return $this->iv;
	}

	/**
	 * Processes whole blocks.
	 *
	 * @param string $data Length a multiple of 16.
	 * @return string
	 * @throws ArchiveException On a partial block or an OpenSSL error.
	 */
	public function update( string $data ): string {
		if ( '' === $data ) {
			return '';
		}
		if ( 0 !== strlen( $data ) % FmwCrypto::BLOCK ) {
			throw new ArchiveException( 'Encrypted data must be processed in whole 16-byte blocks.' );
		}
		$out = $this->encrypt
			? openssl_encrypt( $data, FmwCrypto::CIPHER, $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv )
			: openssl_decrypt( $data, FmwCrypto::CIPHER, $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv );
		if ( false === $out ) {
			throw new ArchiveException( 'Encryption failed.' );
		}
		$this->iv = substr( $this->encrypt ? $out : $data, - FmwCrypto::BLOCK );
		return $out;
	}

	/**
	 * Processes the last piece: pads when encrypting, removes and checks the padding when decrypting.
	 *
	 * @param string $data Rest of the data (when decrypting: whole blocks, at least one).
	 * @return string
	 * @throws ArchiveException On bad padding (wrong key or damaged data).
	 */
	public function finish( string $data ): string {
		if ( $this->encrypt ) {
			$pad = FmwCrypto::BLOCK - strlen( $data ) % FmwCrypto::BLOCK;
			return $this->update( $data . str_repeat( chr( $pad ), $pad ) );
		}
		if ( '' === $data ) {
			throw new ArchiveException( 'Encrypted data is truncated.' );
		}
		$plain = $this->update( $data );
		$pad   = ord( substr( $plain, -1 ) );
		if ( $pad < 1 || $pad > FmwCrypto::BLOCK || substr( $plain, - $pad ) !== str_repeat( chr( $pad ), $pad ) ) {
			throw new ArchiveException( 'Decryption failed: wrong password or damaged data.' );
		}
		return substr( $plain, 0, - $pad );
	}
}
