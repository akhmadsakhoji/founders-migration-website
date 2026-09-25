<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A chunk did not start at the end of the partial upload; carries the real offset.
 */
final class UploadOffsetException extends \RuntimeException {

	/**
	 * Bytes already stored.
	 *
	 * @var int
	 */
	public $offset;

	/**
	 * Constructor.
	 *
	 * @param int $offset Bytes already stored.
	 */
	public function __construct( int $offset ) {
		parent::__construct( sprintf( 'The upload continues at byte %d.', $offset ) );
		$this->offset = $offset;
	}
}
