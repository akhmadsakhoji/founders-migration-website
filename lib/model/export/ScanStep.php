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

use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Walks wp-content and writes every included file, folder and symlink to filelist.ndjson.
 *
 * The walk is depth-first with an explicit stack kept in the job cursor, one
 * folder per iteration, so it resumes after a crash with constant memory.
 * Symlinks are recorded as links and never followed.
 *
 * Reads job options: content_dir (absolute path of wp-content) plus the
 * Filter options. Writes job data: scan = { files, dirs, links, bytes }.
 */
final class ScanStep implements Step {

	const LIST_FILE = 'filelist.ndjson';

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Scan';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the content folder is missing.
	 */
	public function run( Job $job, Context $context ): bool {
		$root = rtrim( (string) ( $job->options['content_dir'] ?? '' ), '/' );
		if ( '' === $root || ! is_dir( $root ) ) {
			throw new JobException( sprintf( 'Content folder "%s" does not exist.', $root ) );
		}

		$filter = new Filter( $job->options );
		$cursor = $job->cursor + array(
			'stack'  => array( '' ),
			'offset' => 0,
			'files'  => 0,
			'dirs'   => 0,
			'links'  => 0,
			'bytes'  => 0,
		);

		$list = new FileList( $context->dir() . '/' . self::LIST_FILE );
		$list->open_for_append( (int) $cursor['offset'] );

		try {
			while ( $cursor['stack'] && $context->should_continue() ) {
				$relative = (string) array_pop( $cursor['stack'] );
				$absolute = '' === $relative ? $root : $root . '/' . $relative;
				$names    = scandir( $absolute );
				if ( false === $names ) {
					$context->log( sprintf( 'Skipped unreadable folder %s.', '' === $relative ? '.' : $relative ) );
					continue;
				}
				sort( $names, SORT_STRING );

				$subfolders = array();
				foreach ( $names as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$child = '' === $relative ? $name : $relative . '/' . $name;
					$entry = $this->describe( $root . '/' . $child, $child, $filter );
					if ( null === $entry ) {
						continue;
					}

					$list->append( $entry );
					if ( 'd' === $entry['t'] ) {
						$subfolders[] = $child;
						++$cursor['dirs'];
					} elseif ( 'l' === $entry['t'] ) {
						++$cursor['links'];
					} else {
						++$cursor['files'];
						$cursor['bytes'] += $entry['s'];
					}
				}

				// Reverse so the alphabetically first folder is walked next.
				foreach ( array_reverse( $subfolders ) as $folder ) {
					$cursor['stack'][] = $folder;
				}

				$job->bytes_done = (int) $cursor['bytes'];
				$context->report_progress();
			}

			$cursor['offset'] = $list->commit();
		} finally {
			$list->close();
		}

		if ( $cursor['stack'] ) {
			$job->cursor = $cursor;
			return false;
		}

		$job->data['scan'] = array(
			'files' => (int) $cursor['files'],
			'dirs'  => (int) $cursor['dirs'],
			'links' => (int) $cursor['links'],
			'bytes' => (int) $cursor['bytes'],
		);
		$job->bytes_done   = 0;
		$job->bytes_total  = (int) $cursor['bytes'];
		$context->log( sprintf( 'Scan found %d files (%d bytes), %d folders, %d symlinks.', $cursor['files'], $cursor['bytes'], $cursor['dirs'], $cursor['links'] ) );
		return true;
	}

	/**
	 * List entry for one path, or null when it is excluded or unsupported.
	 *
	 * @param string $absolute Absolute path.
	 * @param string $relative Path relative to wp-content.
	 * @param Filter $filter   Exclusion rules.
	 * @return array<string,mixed>|null
	 */
	private function describe( string $absolute, string $relative, Filter $filter ): ?array {
		$stat = lstat( $absolute );
		if ( false === $stat ) {
			return null;
		}

		$kind = $stat['mode'] & 0170000;
		if ( 0120000 === $kind ) {
			if ( $filter->excludes( $relative, false ) ) {
				return null;
			}
			$entry = array(
				't' => 'l',
				'l' => (string) readlink( $absolute ),
				'm' => (int) $stat['mtime'],
			);
		} elseif ( 0040000 === $kind ) {
			if ( $filter->excludes( $relative, true ) ) {
				return null;
			}
			$entry = array(
				't' => 'd',
				'o' => $stat['mode'] & 0777,
				'm' => (int) $stat['mtime'],
			);
		} elseif ( 0100000 === $kind ) {
			if ( $filter->excludes( $relative, false ) ) {
				return null;
			}
			$entry = array(
				't' => 'f',
				's' => (int) $stat['size'],
				'o' => $stat['mode'] & 0777,
				'm' => (int) $stat['mtime'],
			);
		} else {
			return null; // Sockets, FIFOs and devices are never backed up.
		}

		// Names that are not valid UTF-8 (old uploads) are kept byte-exact in base64.
		if ( 1 === preg_match( '//u', $relative ) ) {
			$entry['p'] = $relative;
		} else {
			$entry['p'] = (string) preg_replace( '/[^\x20-\x7E]/', '?', $relative );
			$entry['b'] = base64_encode( $relative ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Byte-exact storage of non-UTF-8 file names.
		}

		return $entry;
	}
}
