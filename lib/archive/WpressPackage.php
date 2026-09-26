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

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * The package.json of a .wpress archive: source site, options, encryption and compression.
 *
 * Password check: All-in-One WP Migration stores a fixed sentence encrypted
 * with the backup password ("EncryptedSignature"). We keep only its SHA-256,
 * decrypt the signature with the derived key and compare hashes.
 */
final class WpressPackage {

	/**
	 * SHA-256 of the plain-text signature All-in-One WP Migration encrypts with the password.
	 */
	const SIGNATURE_SHA256 = 'c0c0e76220130ae9e7edb500b8a532300a6a62406b02885b2901441293932553';

	/**
	 * Table prefix All-in-One WP Migration writes into its SQL dumps instead of the real one.
	 */
	const SQL_PREFIX = 'SERVMASK_PREFIX_';

	/**
	 * Decoded package.json.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Decoded package.json.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Parses package.json.
	 *
	 * @param string $json File contents.
	 * @return self
	 * @throws ArchiveException On invalid JSON or an unsupported compression.
	 */
	public static function parse( string $json ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['SiteURL'] ) ) {
			throw new ArchiveException( 'The archive\'s package.json is missing or damaged; this is not a complete All-in-One WP Migration backup.' );
		}
		$package = new self( $data );
		$package->compression(); // Validates the type.
		return $package;
	}

	/**
	 * Finds and parses the top-level package.json of an archive (usually its first entry).
	 *
	 * @param string $path Archive path.
	 * @return self
	 * @throws ArchiveException When the archive is damaged or has no package.json.
	 */
	public static function read( string $path ): self {
		$json = self::read_entry( $path, 'package.json' );
		if ( null === $json ) {
			throw new ArchiveException( 'The archive has no package.json; this is not a complete All-in-One WP Migration backup.' );
		}
		return self::parse( $json );
	}

	/**
	 * Contents of a small top-level entry (package.json, multisite.json), or null when there is none.
	 *
	 * Returned as stored: package.json is always plain; multisite.json is
	 * encrypted and compressed with the archive (see WpressNetwork::read()).
	 *
	 * @param string $path Archive path.
	 * @param string $name Entry name.
	 * @return string|null
	 * @throws ArchiveException When the archive is damaged or the entry is larger than 16 MB.
	 */
	public static function read_entry( string $path, string $name ): ?string {
		$reader = WpressReader::open( $path );
		try {
			$entry = $reader->next();
			while ( null !== $entry ) {
				if ( $name === $entry->name ) {
					if ( $entry->size > 16777216 ) {
						throw new ArchiveException( sprintf( 'The archive\'s %s is larger than 16 MB; it is refused.', $name ) );
					}
					return $reader->read( $entry->size );
				}
				$entry = $reader->next();
			}
		} finally {
			$reader->close();
		}
		return null;
	}

	/**
	 * All decoded data.
	 *
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Version of All-in-One WP Migration that made the backup.
	 *
	 * @return string
	 */
	public function plugin_version(): string {
		return (string) ( $this->data['Plugin']['Version'] ?? '' );
	}

	/**
	 * Whether file contents are encrypted.
	 *
	 * @return bool
	 */
	public function encrypted(): bool {
		return ! empty( $this->data['Encrypted'] );
	}

	/**
	 * Chunk compression: none, gzip or bzip2.
	 *
	 * @return string
	 * @throws ArchiveException On an unknown type.
	 */
	public function compression(): string {
		if ( empty( $this->data['Compression']['Enabled'] ) ) {
			return 'none';
		}
		$type = strtolower( (string) ( $this->data['Compression']['Type'] ?? '' ) );
		if ( ! in_array( $type, array( 'gzip', 'bzip2' ), true ) ) {
			throw new ArchiveException( sprintf( 'Unknown .wpress compression "%s"; update the plugin.', $type ) );
		}
		return $type;
	}

	/**
	 * Source table prefix.
	 *
	 * @return string
	 */
	public function table_prefix(): string {
		return (string) ( $this->data['Database']['Prefix'] ?? 'wp_' );
	}

	/**
	 * Whether the backup was made without the database.
	 *
	 * @return bool
	 */
	public function no_database(): bool {
		return ! empty( $this->data['NoDatabase'] );
	}

	/**
	 * A string value from the WordPress section (Absolute, Content, Uploads, UploadsURL, Version).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function wordpress( string $key ): string {
		$value = $this->data['WordPress'][ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Find / replace pairs chosen when the backup was made (applied on restore).
	 *
	 * @return array<string,string>
	 */
	public function replace_pairs(): array {
		$pairs = array();
		$old   = (array) ( $this->data['Replace']['OldValues'] ?? array() );
		$new   = (array) ( $this->data['Replace']['NewValues'] ?? array() );
		foreach ( $old as $i => $value ) {
			if ( is_string( $value ) && '' !== $value && isset( $new[ $i ] ) && is_string( $new[ $i ] ) && '' !== $new[ $i ] ) {
				$pairs[ $value ] = $new[ $i ];
			}
		}
		return $pairs;
	}

	/**
	 * AES key for a password, checked against the archive's signature.
	 *
	 * @param string $password Password.
	 * @return string 32-byte key.
	 * @throws ArchiveException On a wrong password, or when OpenSSL is missing.
	 */
	public function key_for( string $password ): string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			throw new ArchiveException( 'This backup is encrypted, and PHP\'s OpenSSL extension (needed to decrypt it) is not installed.' );
		}
		$key = self::derive_key( $password );
		if ( ! $this->accepts_key( $key ) ) {
			throw new ArchiveException( 'Wrong password for this encrypted backup.' );
		}
		return $key;
	}

	/**
	 * Whether a derived key matches the archive's signature (always true for archives without one).
	 *
	 * @param string $key 32-byte key.
	 * @return bool
	 */
	public function accepts_key( string $key ): bool {
		$signature = base64_decode( (string) ( $this->data['EncryptedSignature'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Stored by the archive format.
		if ( false === $signature || '' === $signature ) {
			return true; // Old archives have no signature; a wrong password shows up as undecryptable data.
		}
		$plain = WpressDecoder::decrypt( $signature, $key );
		return null !== $plain && hash_equals( self::SIGNATURE_SHA256, hash( 'sha256', $plain ) );
	}

	/**
	 * Key derivation used by All-in-One WP Migration: first 16 bytes of SHA-1, NUL-padded to AES-256 length.
	 *
	 * @param string $password Password.
	 * @return string 32 bytes.
	 */
	public static function derive_key( string $password ): string {
		return str_pad( substr( sha1( $password, true ), 0, 16 ), 32, "\0" );
	}
}
