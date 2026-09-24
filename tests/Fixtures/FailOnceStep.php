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
 * Throws on its first call, succeeds afterwards (a transient failure).
 */
final class FailOnceStep implements Step {

	/**
	 * Whether the next call throws.
	 *
	 * @var bool
	 */
	public static $fail = true;

	public function label(): string {
		return 'Flaky';
	}

	public function run( Job $job, Context $context ): bool {
		if ( self::$fail ) {
			self::$fail = false;
			throw new \RuntimeException( 'Disk full while writing part-0001.tar.gz' );
		}
		return true;
	}
}
