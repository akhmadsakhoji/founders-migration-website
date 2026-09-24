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
use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Reads the archive's manifest and refuses restores that cannot succeed, before anything changes.
 *
 * Reads job options: archive, target { home_url, site_url, abspath,
 * content_dir, table_prefix, multisite }, skip_space_check.
 * Writes job data: manifest (site, options, totals, parts).
 */
final class CheckStep implements Step {

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Check';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the restore cannot go ahead.
	 */
	public function run( Job $job, Context $context ): bool {
		$target = (array) ( $job->options['target'] ?? array() );
		try {
			$manifest = ( new FmwArchive( (string) ( $job->options['archive'] ?? '' ) ) )->manifest();
		} catch ( ArchiveException $e ) {
			throw new JobException( $e->getMessage() );
		}

		$site = (array) ( $manifest['site'] ?? array() );
		if ( ! empty( $site['multisite'] ) || ! empty( $target['multisite'] ) ) {
			if ( empty( $site['multisite'] ) !== empty( $target['multisite'] ) ) {
				throw new JobException( 'Restoring between a single site and a multisite network arrives in phase 3.' );
			}
			if ( rtrim( (string) ( $site['home_url'] ?? '' ), '/' ) !== rtrim( (string) ( $target['home_url'] ?? '' ), '/' ) ) {
				throw new JobException( 'Moving a multisite network to another domain arrives in phase 3; restoring it on the same domain works now.' );
			}
		}

		$raw_files = 0;
		$raw_db    = 0;
		$largest   = 0;
		$has_db    = false;
		foreach ( (array) $manifest['parts'] as $part ) {
			$largest = max( $largest, (int) $part['bytes'] );
			if ( 'database' === $part['type'] ) {
				$raw_db += (int) $part['bytes_raw'];
				$has_db  = true;
			} elseif ( 'files' === $part['type'] ) {
				$raw_files += (int) $part['bytes_raw'];
			} else {
				throw new JobException( sprintf( 'Unknown part type "%s"; update the plugin.', $part['type'] ) );
			}
			if ( ! in_array( $part['compression'], array( 'gzip', 'none' ), true ) ) {
				throw new JobException( sprintf( 'Unknown compression "%s"; update the plugin.', $part['compression'] ) );
			}
		}

		// Staging one part + the extracted files + the database twice (live and imported), plus 10%.
		$needed = (int) ( ( $largest + $raw_files + 2 * $raw_db ) * 1.1 );
		$free   = @disk_free_space( (string) ( $target['content_dir'] ?? '.' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not available on every host; treated as unknown.
		if ( false !== $free && $free < $needed && empty( $job->options['skip_space_check'] ) ) {
			throw new JobException( sprintf( 'Not enough disk space: the restore needs about %d MB, %d MB are free. Free up space or pass --skip-space-check.', (int) ( $needed / 1048576 ), (int) ( $free / 1048576 ) ) );
		}

		if ( $has_db ) {
			$restore = new RestoreDatabase();
			$restore->drop( $restore->tables( RestoreDatabase::TMP ) ); // Leftovers of an earlier, abandoned restore.
			$restore->create_progress();
			$restore->db()->close();
		}

		$job->data['manifest'] = array(
			'site'    => $site,
			'options' => (array) ( $manifest['options'] ?? array() ),
			'totals'  => (array) ( $manifest['totals'] ?? array() ),
			'parts'   => array_values( (array) $manifest['parts'] ),
		);
		$job->data['has_db']   = $has_db;

		$context->log(
			sprintf(
				'Restoring %s (created %s by %s) onto %s: %d parts, %d files, %d tables.',
				(string) ( $site['home_url'] ?? '?' ),
				(string) ( $manifest['created_at'] ?? '?' ),
				(string) ( $manifest['generator'] ?? '?' ),
				(string) ( $target['home_url'] ?? '?' ),
				count( (array) $manifest['parts'] ),
				(int) ( $manifest['totals']['files'] ?? 0 ),
				(int) ( $manifest['totals']['tables'] ?? 0 )
			)
		);
		return true;
	}
}
