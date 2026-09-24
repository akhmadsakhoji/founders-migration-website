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
 * One stage of a job (scan, database, files, package, ...).
 *
 * The Runner calls run() repeatedly and saves the job after every call, so
 * each call must do a bounded slice of work: loop while
 * $context->should_continue() is true, keep the position in $job->cursor,
 * and return. A step must be able to continue from $job->cursor in a brand
 * new process. Update $job->bytes_done as work progresses and call
 * $context->report_progress() to refresh the progress bar within a slice.
 */
interface Step {

	/**
	 * Short label shown in progress output, for example "Database".
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Does one slice of work.
	 *
	 * @param Job     $job     Job; update cursor, data and bytes_done here.
	 * @param Context $context Time budget, logging and working folder.
	 * @return bool True when the step is complete.
	 */
	public function run( Job $job, Context $context ): bool;
}
