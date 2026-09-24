<?php
/**
 * Founders Migration Website
 *
 * Removes plugin settings. Backups and job storage are deliberately kept:
 * deleting a plugin must never delete a user's backups.
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'fmwp_settings' );
delete_site_option( 'fmwp_settings' );
delete_site_transient( 'fmwp_exposure_check' );
