<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Job;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * A step that writes outside the job folder and cleans up when the job is cancelled.
 *
 * The job folder itself is purged by the store; this is for everything else,
 * such as the unfinished archive a backup writes into the backups folder.
 */
interface Discardable {

	/**
	 * Deletes what this step left outside the job folder.
	 *
	 * @param Job $job Cancelled job.
	 * @return void
	 */
	public function discard( Job $job ): void;
}
