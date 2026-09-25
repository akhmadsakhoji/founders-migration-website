<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Raised for a statement in an archive that a restore refuses to run.
 */
final class UnsafeSqlException extends \RuntimeException {
}
