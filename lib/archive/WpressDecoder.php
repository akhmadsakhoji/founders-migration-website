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
 * Turns the stored data of a .wpress entry back into the original file.
 *
 * All-in-One WP Migration processes files in 512,000-byte chunks:
 *
 * - plain archives store the bytes as they are;
 * - encrypted archives store each chunk as a 16-byte IV followed by the
 *   AES-256-CBC ciphertext (so a full chunk takes 512,032 bytes);
 * - compressed archives (zlib "gzip" or bzip2, optionally then encrypted)
 *   prefix each chunk with its stored length as a 32-bit big-endian number.
 *
 * Decoding always stops on a chunk boundary, so a job can save the number of
 * stored bytes consumed and resume there. Each chunk is limited to what one
 * source chunk can produce, so a crafted archive cannot exhaust memory.
 */
final class WpressDecoder {

	const CHUNK_BYTES     = 512000;
	const IV_BYTES        = 16;
	const RAW_READ_BYTES  = 1048576;
	const MAX_CHUNK_BYTES = 1048576;

	/**
	 * AES key, or null for unencrypted archives.
	 *
	 * @var string|null
	 */
	private $key;

	/**
	 * Compression: none, gzip or bzip2.
	 *
	 * @var string
	 */
	private $compression;

	/**
	 * Constructor.
	 *
	 * @param string|null $key         32-byte key (WpressPackage::key_for()) or null.
	 * @param string      $compression none, gzip or bzip2.
	 * @throws ArchiveException When the needed PHP extension is missing.
	 */
	public function __construct( ?string $key, string $compression ) {
		if ( 'bzip2' === $compression && ! in_array( 'bzip2.*', stream_get_filters(), true ) ) {
			throw new ArchiveException( 'This backup is compressed with bzip2, and PHP\'s bz2 extension (needed to read it) is not installed.' );
		}
		if ( 'gzip' === $compression && ! function_exists( 'gzuncompress' ) ) {
			throw new ArchiveException( 'This backup is compressed, and PHP\'s zlib extension (needed to read it) is not installed.' );
		}
		$this->key         = $key;
		$this->compression = $compression;
	}

	/**
	 * Whether entries are stored differently from the original files.
	 *
	 * @return bool
	 */
	public function transforms(): bool {
		return null !== $this->key || 'none' !== $this->compression;
	}

	/**
	 * Decodes (the rest of) the current entry of $reader.
	 *
	 * The caller has called $reader->next() and skipped the $consumed bytes
	 * already done. At least one chunk is decoded per call; $more is asked
	 * after each chunk whether to go on.
	 *
	 * @param WpressReader          $reader   Reader positioned in the entry's data.
	 * @param WpressEntry           $entry    Entry.
	 * @param bool                  $raw      Stored as is (configuration files).
	 * @param int                   $consumed Stored bytes consumed so far; updated.
	 * @param callable(string):void $write    Receives decoded bytes.
	 * @param callable():bool       $more     Whether to decode another chunk.
	 * @return bool Whether the entry is complete.
	 * @throws ArchiveException On damaged data or a wrong password.
	 */
	public function decode( WpressReader $reader, WpressEntry $entry, bool $raw, int &$consumed, callable $write, callable $more ): bool {
		$size = $entry->size;
		while ( $consumed < $size ) {
			$left = $size - $consumed;

			if ( $raw || ! $this->transforms() ) {
				$data      = $this->read( $reader, min( self::RAW_READ_BYTES, $left ), $entry );
				$consumed += strlen( $data );
			} elseif ( 'none' !== $this->compression ) {
				$head   = $this->read( $reader, 4, $entry );
				$length = (int) unpack( 'N', $head )[1];
				if ( $length < 1 || $length > $left - 4 || $length > self::MAX_CHUNK_BYTES ) {
					throw new ArchiveException( sprintf( 'Damaged compressed data in %s.', $entry->name ) );
				}
				$data      = $this->read( $reader, $length, $entry );
				$consumed += 4 + $length;
				if ( null !== $this->key ) {
					$data = $this->decrypt_chunk( $data, $entry );
				}
				$data = $this->decompress( $data, $entry );
			} else {
				$length    = $left > self::CHUNK_BYTES ? min( self::CHUNK_BYTES + 2 * self::IV_BYTES, $left ) : $left;
				$data      = $this->decrypt_chunk( $this->read( $reader, $length, $entry ), $entry );
				$consumed += $length;
			}

			$write( $data );
			if ( $consumed < $size && ! $more() ) {
				break;
			}
		}
		return $consumed >= $size;
	}

	/**
	 * AES-256-CBC decryption of IV + ciphertext.
	 *
	 * @param string $data IV followed by ciphertext.
	 * @param string $key  32-byte key.
	 * @return string|null Null when the data does not decrypt (wrong key or damage).
	 */
	public static function decrypt( string $data, string $key ): ?string {
		if ( strlen( $data ) < 2 * self::IV_BYTES || 0 !== ( strlen( $data ) - self::IV_BYTES ) % 16 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $data, self::IV_BYTES ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $data, 0, self::IV_BYTES ) );
		return false === $plain ? null : $plain;
	}

	/**
	 * Reads exactly $length stored bytes.
	 *
	 * @param WpressReader $reader Reader.
	 * @param int          $length Bytes.
	 * @param WpressEntry  $entry  Entry, for messages.
	 * @return string
	 * @throws ArchiveException When the entry ends first.
	 */
	private function read( WpressReader $reader, int $length, WpressEntry $entry ): string {
		$data = $reader->read( $length );
		if ( strlen( $data ) !== $length ) {
			throw new ArchiveException( sprintf( 'Unexpected end of data in %s.', $entry->name ) );
		}
		return $data;
	}

	/**
	 * Decrypts one chunk or explains why it cannot be.
	 *
	 * @param string      $data  Stored chunk.
	 * @param WpressEntry $entry Entry, for messages.
	 * @return string
	 * @throws ArchiveException On failure.
	 */
	private function decrypt_chunk( string $data, WpressEntry $entry ): string {
		$plain = self::decrypt( $data, (string) $this->key );
		$limit = 'none' === $this->compression ? self::CHUNK_BYTES : self::MAX_CHUNK_BYTES; // Compressed chunks of random data grow a little.
		if ( null === $plain || strlen( $plain ) > $limit ) {
			throw new ArchiveException( sprintf( 'Could not decrypt %s: wrong password or damaged archive.', $entry->name ) );
		}
		return $plain;
	}

	/**
	 * Decompresses one chunk, never producing more than a source chunk.
	 *
	 * @param string      $data  Compressed chunk.
	 * @param WpressEntry $entry Entry, for messages.
	 * @return string
	 * @throws ArchiveException On damaged data.
	 */
	private function decompress( string $data, WpressEntry $entry ): string {
		if ( 'gzip' === $this->compression ) {
			$plain = @gzuncompress( $data, self::CHUNK_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is reported below.
		} else {
			$plain  = false;
			$handle = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
			if ( false !== $handle ) {
				fwrite( $handle, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory stream.
				rewind( $handle );
				if ( false !== @stream_filter_append( $handle, 'bzip2.decompress', STREAM_FILTER_READ ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is reported below.
					$plain = @stream_get_contents( $handle, self::CHUNK_BYTES + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is reported below.
				}
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream.
			}
		}
		if ( ! is_string( $plain ) || '' === $plain || strlen( $plain ) > self::CHUNK_BYTES ) {
			throw new ArchiveException( sprintf( 'Could not decompress %s: the archive is damaged.', $entry->name ) );
		}
		return $plain;
	}
}
