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
 * Raised for connection and query failures; a query failure's code is the MySQL error number.
 */
final class DatabaseException extends \RuntimeException {

	/**
	 * MySQL ER_DUP_ENTRY: a value already taken in a unique key.
	 */
	const DUPLICATE_KEY = 1062;
}
