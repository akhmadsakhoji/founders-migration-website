<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Tests\Fixtures;

use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\Step;

/**
 * Counts to 5, one unit per slice, and reports 5 bytes of progress.
 */
final class CountingStep implements Step {

	/**
	 * Total run() calls across all instances.
	 *
	 * @var int
	 */
	public static $calls = 0;

	public function label(): string {
		return 'Counting';
	}

	public function run( Job $job, Context $context ): bool {
		++self::$calls;
		$job->cursor['n']  = ( $job->cursor['n'] ?? 0 ) + 1;
		$job->bytes_total  = 5;
		$job->bytes_done   = $job->cursor['n'];
		return $job->cursor['n'] >= 5;
	}
}
