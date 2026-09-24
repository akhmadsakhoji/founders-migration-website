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
				$old = $restore->tables( RestoreDatabase::OLD );
				$restore->drop( $old );
				if ( $old ) {
					$context->log( sprintf( 'Removed %d previous tables.', count( $old ) ) );
				}
			} else {
				$context->log( sprintf( 'Previous tables kept as %s*; remove them with `wp fmw cleanup --tables`.', RestoreDatabase::OLD ) );
			}
			$restore->drop( array( RestoreDatabase::PROGRESS ) );
			$restore->db()->close();
		}
		$context->log( 'Restore finished.' );
		return true;
	}
}
