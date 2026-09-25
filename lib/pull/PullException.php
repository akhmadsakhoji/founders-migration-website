<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Pull;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * A pull that failed, with the HTTP status and a code for the API.
 */
final class PullException extends \RuntimeException {

	/**
	 * HTTP status (0 when the other site could not be reached).
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Error code (fmw_pull_*).
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $message    Message.
	 * @param int    $status     HTTP status.
	 * @param string $error_code Error code.
	 */
	public function __construct( string $message, int $status = 0, string $error_code = 'fmw_pull_failed' ) {
		parent::__construct( $message );
		$this->status     = $status;
		$this->error_code = $error_code;
	}

	/**
	 * Whether trying again later may help (network trouble, busy source, server errors).
	 *
	 * @return bool
	 */
	public function is_transient(): bool {
		return 0 === $this->status || 409 === $this->status || 429 === $this->status || $this->status >= 500;
	}
}
