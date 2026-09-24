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
 * Time budget for one Runner call: about 20 seconds per web request, unlimited in WP-CLI.
 */
final class Deadline {

	/**
	 * Absolute end (microtime), null for no limit.
	 *
	 * @var float|null
	 */
	private $end;

	/**
	 * Constructor.
	 *
	 * @param float|null $seconds Budget in seconds; null for no limit.
	 */
	public function __construct( ?float $seconds ) {
		$this->end = null === $seconds ? null : microtime( true ) + $seconds;
	}

	/**
	 * No time limit (WP-CLI).
	 *
	 * @return self
	 */
	public static function unlimited(): self {
		return new self( null );
	}

	/**
	 * Seconds left, or null without a limit.
	 *
	 * @return float|null
	 */
	public function remaining(): ?float {
		return null === $this->end ? null : max( 0.0, $this->end - microtime( true ) );
	}

	/**
	 * Whether the budget is used up.
	 *
	 * @return bool
	 */
	public function expired(): bool {
		return null !== $this->end && microtime( true ) >= $this->end;
	}
}
