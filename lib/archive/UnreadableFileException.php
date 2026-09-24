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
 * Raised when a source file cannot be opened. Nothing has been written for it,
 * so the caller may skip the file and carry on. Write failures (a full disk)
 * are plain ArchiveExceptions and must stop the job.
 */
final class UnreadableFileException extends ArchiveException {
}
