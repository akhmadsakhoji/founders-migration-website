<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Absolute path of the folder that holds finished backups, without a trailing slash.
 *
 * @return string
 */
function fmwp_backups_path() {
	return untrailingslashit( wp_normalize_path( FMWP_BACKUPS_PATH ) );
}

/**
 * Absolute path of the folder that holds job state and temporary parts, without a trailing slash.
 *
 * @return string
 */
function fmwp_storage_path() {
	return untrailingslashit( wp_normalize_path( FMWP_STORAGE_PATH ) );
}

/**
 * Capability required to use the plugin on this install.
 *
 * @return string
 */
function fmwp_capability() {
	return is_multisite() ? 'manage_network_options' : 'manage_options';
}
