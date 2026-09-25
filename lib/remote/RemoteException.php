<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * A remote storage request failed.
 */
final class RemoteException extends \RuntimeException {

	/**
	 * HTTP status (0 for a network error).
	 *
	 * @var int
	 */
	public $status;

	/**
	 * S3 error code, such as NoSuchKey or SignatureDoesNotMatch ('' when unknown).
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $message    Message.
	 * @param int    $status     HTTP status.
	 * @param string $error_code S3 error code.
	 */
	public function __construct( string $message, int $status = 0, string $error_code = '' ) {
		parent::__construct( $message );
		$this->status     = $status;
		$this->error_code = $error_code;
	}

	/**
	 * Whether trying again may help (network trouble, throttling, server errors).
	 *
	 * @return bool
	 */
	public function is_transient(): bool {
		return 0 === $this->status || 429 === $this->status || $this->status >= 500 || in_array( $this->error_code, array( 'RequestTimeout', 'SlowDown', 'InternalError' ), true );
	}
}
