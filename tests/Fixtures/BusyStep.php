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
 * Works in a tight loop until the slice ends, like a real file or database step.
 */
final class BusyStep implements Step {

	public function label(): string {
		return 'Busy';
	}

	public function run( Job $job, Context $context ): bool {
		$job->cursor['slices'] = ( $job->cursor['slices'] ?? 0 ) + 1;
		while ( $context->should_continue() ) {
			usleep( 1000 );
		}
		return $job->cursor['slices'] >= 3;
	}
}
