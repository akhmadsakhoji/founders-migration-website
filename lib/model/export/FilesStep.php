<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Export;

use Founders\Migration\Archive\TarEntry;
use Founders\Migration\Archive\UnreadableFileException;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions -- Job folders are written with native calls, like the archive library.

/**
 * Packs the scanned files into format-v1 parts under <job>/files/.
 *
 * Two parts are open at a time: a .tar.gz part for compressible files and a
 * .tar part for files that are already compressed (see Filter). A part is
 * closed when adding the next file would take it past part_size; a single
 * file is never split across parts. Large files are written in 64 MiB
 * slices, so a job resumes in the middle of a 20 GB video.
 *
 * Reads job options: content_dir, part_size, compression_level, slice_bytes, plus the
 * Filter options. Writes job data: parts (list of finished part records, in
 * manifest format) and files = { parts, skipped, changed }.
 */
final class FilesStep implements Step {

	const PART_DIR          = 'files';
	const DEFAULT_PART_SIZE = 1073741824;
	const SLICE_BYTES       = 67108864;

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Files';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the part folder cannot be created.
	 */
	public function run( Job $job, Context $context ): bool {
		$root      = rtrim( (string) ( $job->options['content_dir'] ?? '' ), '/' );
		$part_size = max( 1, (int) ( $job->options['part_size'] ?? self::DEFAULT_PART_SIZE ) );
		$level     = (int) ( $job->options['compression_level'] ?? 6 );
		$slice     = max( 1, (int) ( $job->options['slice_bytes'] ?? self::SLICE_BYTES ) );
		$filter    = new Filter( $job->options );
		$part_dir  = $context->dir() . '/' . self::PART_DIR;

		if ( ! is_dir( $part_dir ) && ! mkdir( $part_dir, 0700, true ) && ! is_dir( $part_dir ) ) {
			throw new JobException( sprintf( 'Cannot create %s.', $part_dir ) );
		}

		// Work on copies: the job is only updated when the slice ends cleanly,
		// so a failure never records a part whose cursor was not saved.
		$cursor           = $job->cursor + array(
			'offset'       => 0,
			'entry_offset' => 0,
			'next_part'    => 1,
			'open'         => array(),
			'bytes'        => 0,
			'skipped'      => 0,
			'changed'      => 0,
		);
		$parts            = isset( $job->data['parts'] ) ? (array) $job->data['parts'] : array();
		$open             = new OpenParts( $part_dir, $part_size, $level, $cursor, $parts );
		$job->bytes_total = (int) ( $job->data['scan']['bytes'] ?? $job->bytes_total );
		$job->bytes_done  = (int) $cursor['bytes'];

		$list = new FileList( $context->dir() . '/' . ScanStep::LIST_FILE );
		$list->open_for_read( (int) $cursor['offset'] );
		$done = false;

		try {
			while ( $context->should_continue() ) {
				$next = $list->next();
				if ( null === $next ) {
					$done = true;
					break;
				}
				list( $entry, $after ) = $next;

				$name     = isset( $entry['b'] ) ? (string) base64_decode( (string) $entry['b'], true ) : (string) $entry['p']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Byte-exact non-UTF-8 file names.
				$absolute = $root . '/' . $name;
				$mtime    = (int) ( $entry['m'] ?? 0 );

				if ( 'd' === $entry['t'] ) {
					$open->writer( 'gz', 0 )->add_directory( $name, (int) ( $entry['o'] ?? 0755 ), $mtime );
					$open->count_entry( 'gz' );
					$cursor['offset'] = $after;
					continue;
				}
				if ( 'l' === $entry['t'] ) {
					$open->writer( 'gz', 0 )->add_symlink( $name, (string) ( $entry['l'] ?? '' ), $mtime );
					$open->count_entry( 'gz' );
					$cursor['offset'] = $after;
					continue;
				}

				$size = (int) ( $entry['s'] ?? 0 );
				$kind = $filter->is_stored( $name ) ? 'tar' : 'gz';

				if ( 0 === $cursor['entry_offset'] && ! is_readable( $absolute ) ) {
					$context->log( sprintf( 'Skipped %s: missing or unreadable.', $name ) );
					++$cursor['skipped'];
					$cursor['offset'] = $after;
					continue;
				}

				// Rotation happens only before a file starts, never in the middle of one.
				$writer   = $open->writer( $kind, 0 === $cursor['entry_offset'] ? $size : 0 );
				$warnings = count( $writer->warnings() );
				$tar      = TarEntry::file( $name, $size, (int) ( $entry['o'] ?? 0644 ), $mtime );
				$skipped  = false;

				do {
					$before = $cursor['entry_offset'];
					try {
						$cursor['entry_offset'] = $writer->write_file_slice( $absolute, $tar, $before, $slice );
					} catch ( UnreadableFileException $e ) {
						// Only raised before anything of this file was written.
						$context->log( sprintf( 'Skipped %s: %s', $name, $e->getMessage() ) );
						++$cursor['skipped'];
						$skipped = true;
						break;
					}
					$cursor['bytes'] += $cursor['entry_offset'] - $before;
					$job->bytes_done  = (int) $cursor['bytes'];
					$context->report_progress();
				} while ( $cursor['entry_offset'] < $size && $context->should_continue() );

				if ( ! $skipped ) {
					foreach ( array_slice( $writer->warnings(), $warnings ) as $warning ) {
						$context->log( $warning );
						++$cursor['changed'];
					}
					if ( $cursor['entry_offset'] < $size ) {
						break; // Slice over in the middle of this file; continue it next time.
					}
					$open->count_entry( $kind );
				}

				$cursor['entry_offset'] = 0;
				$cursor['offset']       = $after;
			}

			if ( $done ) {
				$open->finish_all();
			} else {
				$open->commit_all();
			}
		} finally {
			$open->close_all();
			$list->close();
		}

		$cursor['open']      = $open->state();
		$cursor['next_part'] = $open->next_number();
		$job->data['parts']  = $open->parts();
		$job->bytes_done     = (int) $cursor['bytes'];

		if ( ! $done ) {
			$job->cursor = $cursor;
			return false;
		}

		$job->data['files'] = array(
			'parts'   => count( $job->data['parts'] ),
			'skipped' => (int) $cursor['skipped'],
			'changed' => (int) $cursor['changed'],
		);
		$context->log( sprintf( 'Packed %d bytes into %d parts (%d skipped, %d changed during backup).', $cursor['bytes'], count( $job->data['parts'] ), $cursor['skipped'], $cursor['changed'] ) );
		return true;
	}
}
