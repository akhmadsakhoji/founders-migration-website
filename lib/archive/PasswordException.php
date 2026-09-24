<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Archive;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * An encrypted backup needs a password, or the one given is wrong.
 */
final class PasswordException extends ArchiveException {

	/**
	 * Whether a password was given at all.
	 *
	 * @var bool
	 */
	public $given;

	/**
	 * Constructor.
	 *
	 * @param bool $given Whether a password was given.
	 */
	public function __construct( bool $given ) {
		parent::__construct( $given ? 'Wrong password for this encrypted backup (or the backup is damaged).' : 'This backup is encrypted; a password is required.' );
		$this->given = $given;
	}
}
