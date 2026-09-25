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
use Founders\Migration\Archive\PathGuard;
use Founders\Migration\Archive\WpressReader;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Writes the site files of a .wpress archive, one entry at a time, resumably.
 *
 * Archive paths are relative to wp-content; the first folder is mapped to the
 * target site's real location (uploads, plugins, mu-plugins and themes can
 * live elsewhere). Protected paths (this plugin, the FMW folders) are
 * skipped. Large files resume mid-file on a chunk boundary.
 *
 * Reads job options: archive, target, protect_paths. Reads job data: wpress.
 */
final class WpressFilesStep implements Step {

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Restore';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException On damaged data or an unsafe path.
	 */
	public function run( Job $job, Context $context ): bool {
		$target  = (array) ( $job->data['network_target'] ?? $job->options['target'] ?? array() ); // A site of a network: its media folder is mapped below.
		$archive = (string) ( $job->options['archive'] ?? '' );
		$cursor  = $job->cursor + array(
			'offset'  => 0,
			'files'   => 0,
			'skipped' => 0,
		);
		$protect = self::protected_paths( $job );
		$writer  = new WpressEntryWriter( $job );

		$job->bytes_total = (int) filesize( $archive );

		try {
			do {
				$reader = WpressReader::open( $archive, (int) $cursor['offset'] );
				try {
					$entry = $reader->next();
				} finally {
					$reader->close();
				}
				if ( null === $entry ) {
					$job->bytes_done = $job->bytes_total;
					$context->log( sprintf( 'Restored %d files%s.', (int) $cursor['files'], $cursor['skipped'] ? sprintf( ' (%d protected files left as they are)', (int) $cursor['skipped'] ) : '' ) );
					return true;
				}

				$relative = PathGuard::relative( $entry->name );
				if ( in_array( $relative, WpressCheckStep::CONFIG_FILES, true ) ) {
					self::advance( $cursor, $entry->data_offset() + $entry->size );
					continue;
				}
				if ( is_array( $job->data['import'] ?? null ) ) {
					$relative = SubsiteImport::file( $relative, 'uploads', (int) $job->data['import']['blog_id'] );
					if ( null === $relative ) {
						$job->data['import_left'] = (int) ( $job->data['import_left'] ?? 0 ) + 1; // mu-plugins and drop-ins would change every site.
						self::advance( $cursor, $entry->data_offset() + $entry->size );
						continue;
					}
				}
				$path = self::target_path( $relative, $target );
				if ( self::is_protected( $path, $protect ) ) {
					++$cursor['skipped'];
					self::advance( $cursor, $entry->data_offset() + $entry->size );
					continue;
				}

				$file = (array) ( $cursor['file'] ?? array() );
				if ( $writer->write( $entry->offset, $path, $file, $context ) ) {
					++$cursor['files'];
					self::advance( $cursor, $entry->data_offset() + $entry->size );
				} else {
					$cursor['file'] = $file;
				}
				$job->bytes_done = $entry->data_offset() + (int) ( $file['in'] ?? 0 );
				$context->report_progress();
			} while ( $context->should_continue() );
		} catch ( ArchiveException $e ) {
			throw new JobException( $e->getMessage() );
		}

		$job->cursor = $cursor;
		return false;
	}

	/**
	 * Where an archive path goes on this site.
	 *
	 * @param string              $relative Safe path relative to wp-content.
	 * @param array<string,mixed> $target   Target site (content_dir, uploads_dir, plugins_dir, mu_plugins_dir, themes_dir).
	 * @return string
	 */
	public static function target_path( string $relative, array $target ): string {
		$roots = array(
			'uploads'    => 'uploads_dir',
			'plugins'    => 'plugins_dir',
			'mu-plugins' => 'mu_plugins_dir',
			'themes'     => 'themes_dir',
		);
		$parts = explode( '/', $relative, 2 );
		if ( isset( $parts[1], $roots[ $parts[0] ] ) && ! empty( $target[ $roots[ $parts[0] ] ] ) ) {
			return rtrim( (string) $target[ $roots[ $parts[0] ] ], '/' ) . '/' . $parts[1];
		}
		return rtrim( (string) ( $target['content_dir'] ?? '' ), '/' ) . '/' . $relative;
	}

	/**
	 * Absolute protected paths.
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	private static function protected_paths( Job $job ): array {
		$content = rtrim( (string) ( $job->options['target']['content_dir'] ?? '' ), '/' );
		$paths   = array();
		foreach ( (array) ( $job->options['protect_paths'] ?? array() ) as $path ) {
			$path = trim( (string) $path, '/' );
			if ( '' !== $path ) {
				$paths[] = $content . '/' . $path;
			}
		}
		return $paths;
	}

	/**
	 * Whether a path is inside a protected one.
	 *
	 * @param string   $path    Absolute path.
	 * @param string[] $protect Protected paths.
	 * @return bool
	 */
	private static function is_protected( string $path, array $protect ): bool {
		foreach ( $protect as $root ) {
			if ( $path === $root || 0 === strpos( $path, $root . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Moves the cursor to the next entry.
	 *
	 * @param array<string,mixed> $cursor Cursor.
	 * @param int                 $offset Next header offset.
	 * @return void
	 */
	private static function advance( array &$cursor, int $offset ): void {
		$cursor['offset'] = $offset;
		unset( $cursor['file'] );
	}
}
