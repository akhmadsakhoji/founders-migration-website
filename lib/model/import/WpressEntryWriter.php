<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Import;

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\Crc32;
use Founders\Migration\Archive\WpressDecoder;
use Founders\Migration\Archive\WpressEntry;
use Founders\Migration\Archive\WpressReader;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Secrets;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-GB files with native file calls.

/**
 * Decodes one .wpress entry into a file, resumably, and checks its CRC-32.
 *
 * The cursor keeps the stored bytes consumed ("in", always on a chunk
 * boundary), the bytes written ("out") and the CRC of what was written so
 * far ("crc", combined across slices), plus whether the entry is stored as
 * is ("raw").
 *
 * Which entries are stored as is: the top-level package.json and
 * multisite.json always. Older All-in-One WP Migration versions also skipped
 * encryption for every file named package.json; such a file is recognised
 * by being valid JSON, which encrypted or compressed data never is.
 */
final class WpressEntryWriter {

	/**
	 * Archive path.
	 *
	 * @var string
	 */
	private $archive;

	/**
	 * Decoder.
	 *
	 * @var WpressDecoder
	 */
	private $decoder;

	/**
	 * Constructor.
	 *
	 * @param Job $job Job with options archive, secret_wpress_key and data compression, encrypted.
	 * @throws JobException When the key is missing.
	 */
	public function __construct( Job $job ) {
		$key = null;
		if ( ! empty( $job->data['encrypted'] ) ) {
			$key = (string) Secrets::open( $job->options['secret_wpress_key'] ?? null );
			if ( 32 !== strlen( $key ) ) {
				throw new JobException( 'This backup is encrypted and the job has no usable key (the site\'s salts may have changed); start the restore again with the password.' );
			}
		}
		$this->archive = (string) ( $job->options['archive'] ?? '' );
		$this->decoder = new WpressDecoder( $key, (string) ( $job->data['compression'] ?? 'none' ) );
	}

	/**
	 * Writes (the rest of) the entry whose header is at $offset.
	 *
	 * @param int                 $offset  Header offset.
	 * @param string              $target  File to write.
	 * @param array<string,mixed> $cursor  in, out, crc, raw; updated.
	 * @param Context             $context Context.
	 * @return bool Whether the file is complete.
	 * @throws ArchiveException On damaged data.
	 * @throws JobException On a write error or a CRC mismatch.
	 */
	public function write( int $offset, string $target, array &$cursor, Context $context ): bool {
		$reader = WpressReader::open( $this->archive, $offset );
		try {
			$entry = $reader->next();
			if ( null === $entry ) {
				throw new ArchiveException( 'The archive changed since it was checked.' );
			}
			if ( ! isset( $cursor['raw'] ) ) {
				$cursor['raw'] = $this->stored_as_is( $reader, $entry );
				$reader->close();
				$reader = WpressReader::open( $this->archive, $offset );
				$entry  = $reader->next();
				if ( null === $entry ) {
					throw new ArchiveException( 'The archive changed since it was checked.' );
				}
			}
			$in  = (int) ( $cursor['in'] ?? 0 );
			$out = (int) ( $cursor['out'] ?? 0 );
			$reader->skip_bytes( $in );

			$folder = dirname( $target );
			if ( ! is_dir( $folder ) && ! mkdir( $folder, 0755, true ) && ! is_dir( $folder ) ) {
				throw new JobException( sprintf( 'Cannot create %s.', $folder ) );
			}
			if ( 0 === $out && is_link( $target ) ) {
				unlink( $target );
			}
			$handle = fopen( $target, 'c+b' );
			if ( false === $handle || ! ftruncate( $handle, $out ) || 0 !== fseek( $handle, $out ) ) {
				throw new JobException( sprintf( 'Cannot write %s.', $target ) );
			}

			$hash    = hash_init( 'crc32b' );
			$written = 0;
			try {
				$done = $this->decoder->decode(
					$reader,
					$entry,
					(bool) $cursor['raw'],
					$in,
					static function ( string $data ) use ( $handle, $hash, &$written, $target ): void {
						if ( false === fwrite( $handle, $data ) ) {
							throw new JobException( sprintf( 'Write failed for %s. The disk may be full.', $target ) );
						}
						hash_update( $hash, $data );
						$written += strlen( $data );
					},
					array( $context, 'should_continue' )
				);
			} finally {
				fclose( $handle );
			}
		} finally {
			$reader->close();
		}

		$piece         = hash_final( $hash );
		$cursor['crc'] = 0 === $out ? $piece : Crc32::combine( (string) $cursor['crc'], $piece, $written );
		$cursor['in']  = $in;
		$cursor['out'] = $out + $written;
		if ( ! $done ) {
			return false;
		}

		if ( '' !== $entry->crc32 && ! hash_equals( $entry->crc32, (string) $cursor['crc'] ) ) {
			unlink( $target );
			throw new JobException( sprintf( '%s is damaged in the archive (CRC-32 mismatch).', $entry->name ) );
		}
		chmod( $target, 0644 );
		if ( $entry->mtime > 0 ) {
			touch( $target, $entry->mtime );
		}
		return true;
	}

	/**
	 * Whether an entry's data is stored without encryption or compression.
	 *
	 * @param WpressReader $reader Reader positioned on the entry.
	 * @param WpressEntry  $entry  Entry.
	 * @return bool
	 */
	private function stored_as_is( WpressReader $reader, WpressEntry $entry ): bool {
		if ( in_array( $entry->name, array( 'package.json', 'multisite.json' ), true ) || ! $this->decoder->transforms() ) {
			return true;
		}
		if ( 'package.json' !== basename( $entry->name ) || 0 === $entry->size || $entry->size > 16777216 ) {
			return false;
		}
		json_decode( $reader->read( $entry->size ) );
		return JSON_ERROR_NONE === json_last_error();
	}
}
