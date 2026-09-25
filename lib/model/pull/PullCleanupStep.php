<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Pull;

use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Step;
use Founders\Migration\Pull\PullException;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes the backup the pull made on the source, once the copy here is
 * proven intact (restored, or verified part by part). Existing backups
 * (--backup) and --keep-source-backup are left alone. A failure is logged,
 * not fatal: the copy here is fine, and the backup can be deleted there by hand.
 *
 * Reads job options: pull (keep_source). Reads job data: pull (name, made, verified).
 */
final class PullCleanupStep implements Step {

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Clean up';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 */
	public function run( Job $job, Context $context ): bool {
		$pull = (array) ( $job->data['pull'] ?? array() );
		$name = (string) ( $pull['name'] ?? '' );
		if ( '' === $name || empty( $pull['made'] ) || ! empty( $pull['deleted'] ) ) {
			return true;
		}
		if ( ! empty( $job->options['pull']['keep_source'] ) ) {
			$context->log( sprintf( 'Kept %s on the source site, as asked.', $name ) );
			return true;
		}
		if ( isset( $pull['verified'] ) && false === $pull['verified'] ) {
			$context->log( sprintf( 'Kept %s on the source site: the copy here could not be checked without its password.', $name ) );
			return true;
		}
		try {
			RemoteBackupStep::client( $job )->delete( $name );
			$job->data['pull']['deleted'] = true;
			$context->log( sprintf( 'Deleted the temporary backup %s on the source site.', $name ) );
		} catch ( JobException | PullException $e ) {
			$context->log( sprintf( 'Could not delete %s on the source site (%s); delete it there by hand.', $name, $e->getMessage() ) );
		}
		return true;
	}
}
