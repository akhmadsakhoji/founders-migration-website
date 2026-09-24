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
use Founders\Migration\Job\Runner;
use Founders\Migration\Job\Step;

/**
 * Simulates Ctrl+C arriving while the step works.
 */
final class StopStep implements Step {

	/**
	 * Runner to interrupt.
	 *
	 * @var Runner|null
	 */
	public static $runner = null;

	public function label(): string {
		return 'Interrupted';
	}

	public function run( Job $job, Context $context ): bool {
		if ( null !== self::$runner ) {
			self::$runner->request_stop();
		}
		return true; // The step finishes its slice; the runner pauses before the next one.
	}
}
