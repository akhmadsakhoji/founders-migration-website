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
	 * Decoded manifest.json.
	 *
	 * @return array<string,mixed>
	 * @throws ArchiveException When the manifest is missing, encrypted or invalid.
	 */
	public function manifest(): array {
		$header = $this->header();
		if ( ! empty( $header['encrypted'] ) ) {
			throw new ArchiveException( 'This backup is encrypted; a password is required.' );
		}

		$reader = TarReader::open( $this->path );
		try {
			$entry = $reader->next();
			while ( null !== $entry ) {
				if ( 'manifest.json' === $entry->name ) {
					$manifest = $this->decode( $reader, $entry );
					if ( ! isset( $manifest['parts'] ) || ! is_array( $manifest['parts'] ) ) {
						throw new ArchiveException( 'The manifest has no part list.' );
					}
					return $manifest;
				}
				$entry = $reader->next();
			}
		} finally {
			$reader->close();
		}
		throw new ArchiveException( 'The archive has no manifest.json; it may be incomplete.' );
	}

	/**
	 * Checks every part against the manifest (presence, size, SHA-256).
	 *
	 * @param callable(int,int): void|null $progress Called with bytes checked and total bytes.
	 * @return string[] Problems found; empty when the archive is intact.
	 */
	public function verify( ?callable $progress = null ): array {
		try {
			$manifest = $this->manifest();
		} catch ( ArchiveException $e ) {
			return array( $e->getMessage() );
		}

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
				if ( 'fmw.json' === $name || 'manifest.json' === $name ) {
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
				$size = 0;
				$data = $reader->read( 1048576 );
				while ( '' !== $data ) {
					hash_update( $hash, $data );
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
