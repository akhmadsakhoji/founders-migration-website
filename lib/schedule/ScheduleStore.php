<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Schedule;

use Founders\Migration\Storage\CollectionStore;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Backup schedules, in fmw-storage/schedules.json (see CollectionStore).
 */
final class ScheduleStore extends CollectionStore {

	/**
	 * Constructor.
	 *
	 * @param string $file JSON file path.
	 */
	public function __construct( string $file ) {
		parent::__construct( $file, 'schedules' );
	}
}
