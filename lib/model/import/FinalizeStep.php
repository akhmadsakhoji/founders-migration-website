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

use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Removes the previous tables (unless kept) and the restore's bookkeeping.
 *
 * Reads job options: keep_old_tables.
 */
final class FinalizeStep implements Step {

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Finish';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 */
	public function run( Job $job, Context $context ): bool {
		if ( ! empty( $job->data['has_db'] ) ) {
			$restore = new RestoreDatabase();
			if ( empty( $job->options['keep_old_tables'] ) ) {
				$old = self::moved_aside( $job, $restore );
				$restore->drop( $old );
				if ( $old ) {
					$context->log( sprintf( 'Removed %d previous tables.', count( $old ) ) );
				}
			} else {
				$context->log( sprintf( 'Previous tables kept as %s*; remove them with `wp fmw cleanup --tables`.', RestoreDatabase::OLD ) );
			}
			$restore->drop( array( RestoreDatabase::PROGRESS, SubsiteImport::USERMAP ) );
			$restore->db()->close();
		}
		$context->log( 'reset' === $job->type ? 'Reset finished.' : 'Restore finished.' );
		return true;
	}

	/**
	 * The fmwold_* tables this job moved aside: not those another restore or reset kept on purpose
	 * (a site of the network reset with --keep-old-tables). Jobs that did not record them fall back
	 * to their sites' fmwold_<id>_* tables, or to every fmwold_* table on a single site.
	 *
	 * @param Job             $job     Job.
	 * @param RestoreDatabase $restore Database helper.
	 * @return string[]
	 */
	private static function moved_aside( Job $job, RestoreDatabase $restore ): array {
		$all = $restore->tables( RestoreDatabase::OLD );
		if ( is_array( $job->data['old_tables'] ?? null ) ) {
			return array_values( array_intersect( $all, array_map( 'strval', $job->data['old_tables'] ) ) );
		}
		$sites = array();
		if ( (int) ( $job->options['reset_site'] ?? 0 ) > 0 ) {
			$sites[] = (int) $job->options['reset_site'];
		} elseif ( is_array( $job->data['import'] ?? null ) ) {
			$sites = array_map( 'intval', array_column( SubsiteImport::sites( $job->data['import'] ), 'blog_id' ) );
		}
		if ( ! $sites ) {
			return $all;
		}
		$old = array();
		foreach ( $sites as $site ) {
			$old = array_merge( $old, $restore->tables( RestoreDatabase::OLD . $site . '_' ) );
		}
		return $old;
	}
}
