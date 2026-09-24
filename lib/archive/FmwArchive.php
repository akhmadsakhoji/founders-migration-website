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
 * Reads a .fmw container: header, manifest and part integrity.
 *
 * Reading the manifest walks the TAR headers and seeks over part data, so it
 * takes one small read per entry even for a 100 GB archive.
 */
final class FmwArchive {

	const SUPPORTED_VERSION = 1;
	const MAX_JSON_BYTES    = 16777216;

	/**
	 * Archive path.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Constructor.
	 *
	 * @param string $path Archive path.
	 * @throws ArchiveException When the file does not exist.
	 */
	public function __construct( string $path ) {
		if ( ! is_file( $path ) ) {
			throw new ArchiveException( sprintf( '%s does not exist.', $path ) );
		}
		$this->path = $path;
	}

	/**
	 * Decoded fmw.json (always the first entry).
	 *
	 * @return array<string,mixed>
	 * @throws ArchiveException When the file is not a supported .fmw archive.
	 */
	public function header(): array {
		$reader = TarReader::open( $this->path );
		try {
			$entry = $reader->next();
			if ( null === $entry || 'fmw.json' !== $entry->name ) {
				throw new ArchiveException( 'Not an FMW archive: fmw.json is missing.' );
			}
			$header = $this->decode( $reader, $entry );
		} finally {
			$reader->close();
		}

		if ( 'fmw' !== ( $header['format'] ?? null ) ) {
			throw new ArchiveException( 'Not an FMW archive: unknown format.' );
		}
		if ( ! is_int( $header['version'] ?? null ) || $header['version'] > self::SUPPORTED_VERSION ) {
			throw new ArchiveException( sprintf( 'Archive format version %s is newer than this plugin supports (%d). Update the plugin.', (string) ( $header['version'] ?? '?' ), self::SUPPORTED_VERSION ) );
		}
		return $header;
	}

	/**
	 * Whether the backup is encrypted (needs a password for its manifest and parts).
	 *
	 * @return bool
	 */
	public function encrypted(): bool {
		return ! empty( $this->header()['encrypted'] );
	}

	/**
	 * Decoded manifest.json (manifest.json.enc for encrypted backups, which needs the password).
	 *
	 * @param string|null $password Password of an encrypted backup.
	 * @return array<string,mixed>
	 * @throws ArchiveException When the manifest is missing or invalid.
	 * @throws PasswordException When the password is missing or wrong.
	 */
	public function manifest( ?string $password = null ): array {
		$header    = $this->header();
		$encrypted = ! empty( $header['encrypted'] );
		if ( $encrypted && ( null === $password || '' === $password ) ) {
			throw new PasswordException( false );
		}
		if ( $encrypted && 1 !== preg_match( '/^(?!0{64}$)[0-9a-f]{64}$/', (string) ( $header['manifest_hmac'] ?? '' ) ) ) {
			throw new ArchiveException( 'The archive is incomplete: fmw.json has no manifest HMAC.' );
		}
		$wanted = $encrypted ? 'manifest.json.enc' : 'manifest.json';

		$reader = TarReader::open( $this->path );
		try {
			$entry = $reader->next();
			while ( null !== $entry ) {
				if ( $wanted === $entry->name ) {
					if ( $entry->size > self::MAX_JSON_BYTES ) {
						throw new ArchiveException( sprintf( '%s is unreasonably large.', $entry->name ) );
					}
					$json = $reader->read( $entry->size );
					if ( $encrypted ) {
						try {
							$json = FmwCrypto::decrypt_string( $json, (string) $password, self::iterations( $header ), (string) ( $header['manifest_hmac'] ?? '' ) );
						} catch ( ArchiveException $e ) {
							throw new PasswordException( true );
						}
					}
					$manifest = json_decode( $json, true );
					if ( ! is_array( $manifest ) || ! isset( $manifest['parts'] ) || ! is_array( $manifest['parts'] ) ) {
						throw new ArchiveException( 'The manifest is not valid or has no part list.' );
					}
					return $manifest;
				}
				$entry = $reader->next();
			}
		} finally {
			$reader->close();
		}
		throw new ArchiveException( sprintf( 'The archive has no %s; it may be incomplete.', $wanted ) );
	}

	/**
	 * PBKDF2 iterations from fmw.json, within sane limits.
	 *
	 * @param array<string,mixed> $header Decoded fmw.json.
	 * @return int
	 * @throws ArchiveException On unsupported settings.
	 */
	public static function iterations( array $header ): int {
		$kdf        = (array) ( $header['kdf'] ?? array() );
		$iterations = (int) ( $kdf['iterations'] ?? 0 );
		if ( 'pbkdf2-sha256' !== ( $kdf['algorithm'] ?? '' ) || FmwCrypto::CIPHER !== ( $header['cipher'] ?? '' ) ) {
			throw new ArchiveException( 'Unsupported encryption settings; update the plugin.' );
		}
		if ( $iterations < 10000 || $iterations > 10000000 ) {
			throw new ArchiveException( 'Unreasonable key derivation settings in fmw.json.' );
		}
		return $iterations;
	}

	/**
	 * Checks every part against the manifest (presence, size, SHA-256, and HMAC when encrypted).
	 *
	 * @param callable(int,int): void|null $progress Called with bytes checked and total bytes.
	 * @param string|null                  $password Password of an encrypted backup.
	 * @return string[] Problems found; empty when the archive is intact.
	 */
	public function verify( ?callable $progress = null, ?string $password = null ): array {
		try {
			$header   = $this->header();
			$manifest = $this->manifest( $password );
		} catch ( ArchiveException $e ) {
			return array( $e->getMessage() );
		}
		$encrypted = ! empty( $header['encrypted'] );

		$expected = array();
		foreach ( $manifest['parts'] as $part ) {
			$expected[ (string) $part['path'] ] = $part;
		}
		$total    = (int) array_sum( array_column( $manifest['parts'], 'bytes' ) );
		$done     = 0;
		$problems = array();
		$seen     = array();

		$reader = TarReader::open( $this->path );
		try {
			$entry = $reader->next();
			while ( null !== $entry ) {
				$name = $entry->name;
				if ( 'fmw.json' === $name || 'manifest.json' === $name || 'manifest.json.enc' === $name ) {
					$entry = $reader->next();
					continue;
				}
				if ( ! isset( $expected[ $name ] ) ) {
					$problems[] = sprintf( 'Unexpected entry %s.', $name );
					$entry      = $reader->next();
					continue;
				}
				if ( isset( $seen[ $name ] ) ) {
					$problems[] = sprintf( 'Part %s appears twice.', $name );
				}
				$seen[ $name ] = true;

				$hash = hash_init( 'sha256' );
				$mac  = null;
				$size = 0;
				$data = $reader->read( 1048576 );
				if ( $encrypted && strlen( $data ) >= FmwCrypto::HEADER ) {
					try {
						$keys = FmwCrypto::keys( (string) $password, FmwCrypto::salt( substr( $data, 0, FmwCrypto::HEADER ) ), self::iterations( $header ) );
						$mac  = hash_init( 'sha256', HASH_HMAC, $keys['mac'] );
					} catch ( ArchiveException $e ) {
						$problems[] = sprintf( 'Part %s: %s', $name, $e->getMessage() );
					}
				}
				while ( '' !== $data ) {
					hash_update( $hash, $data );
					if ( null !== $mac ) {
						hash_update( $mac, $data );
					}
					$size += strlen( $data );
					$done += strlen( $data );
					if ( null !== $progress ) {
						$progress( $done, $total );
					}
					$data = $reader->read( 1048576 );
				}

				if ( $size !== (int) $expected[ $name ]['bytes'] ) {
					$problems[] = sprintf( 'Part %s is %d bytes; the manifest says %d.', $name, $size, $expected[ $name ]['bytes'] );
				} elseif ( ! hash_equals( (string) $expected[ $name ]['sha256'], hash_final( $hash ) ) ) {
					$problems[] = sprintf( 'Part %s is corrupt (SHA-256 mismatch).', $name );
				} elseif ( null !== $mac && ! hash_equals( (string) ( $expected[ $name ]['hmac'] ?? '' ), hash_final( $mac ) ) ) {
					$problems[] = sprintf( 'Part %s failed its HMAC check (tampered, or a different password).', $name );
				}
				$entry = $reader->next();
			}
		} catch ( ArchiveException $e ) {
			$problems[] = 'The archive is damaged: ' . $e->getMessage();
		} finally {
			$reader->close();
		}

		foreach ( array_keys( $expected ) as $name ) {
			if ( ! isset( $seen[ $name ] ) ) {
				$problems[] = sprintf( 'Part %s is missing.', $name );
			}
		}

		return $problems;
	}

	/**
	 * Reads and decodes a small JSON entry.
	 *
	 * @param TarReader $reader Reader positioned on the entry.
	 * @param TarEntry  $entry  Entry.
	 * @return array<string,mixed>
	 * @throws ArchiveException When it is too large or not valid JSON.
	 */
	private function decode( TarReader $reader, TarEntry $entry ): array {
		if ( $entry->size > self::MAX_JSON_BYTES ) {
			throw new ArchiveException( sprintf( '%s is unreasonably large.', $entry->name ) );
		}
		$data = json_decode( $reader->read( $entry->size ), true );
		if ( ! is_array( $data ) ) {
			throw new ArchiveException( sprintf( '%s is not valid JSON.', $entry->name ) );
		}
		return $data;
	}
}
