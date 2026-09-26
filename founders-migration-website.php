<?php
/**
 * Plugin Name:       Founders Migration Website
 * Plugin URI:        https://github.com/akhmadsakhoji/founders-migration-website
 * Description:       Backup, restore, and migrate WordPress sites up to 100 GB and beyond, using open and standard archive formats (TAR, gzip, SQL).
 * Version:           1.0.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PT Founder Media Partner
 * Author URI:        https://founders.co.id
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       founders-migration-website
 * Domain Path:       /languages
 * Network:           true
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/*
 * This file must stay parseable by very old PHP versions so that an
 * incompatible server shows a readable notice instead of a fatal error.
 * Keep modern syntax out of this file.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) || PHP_INT_SIZE < 8 ) {
	if ( ! function_exists( 'fmwp_incompatible_php_notice' ) ) {
		/**
		 * Explains why the plugin did not load.
		 *
		 * @return void
		 */
		function fmwp_incompatible_php_notice() {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Founders Migration Website requires 64-bit PHP 7.4 or newer. The plugin has not been loaded.', 'founders-migration-website' );
			echo '</p></div>';
		}
	}
	add_action( 'admin_notices', 'fmwp_incompatible_php_notice' );
	add_action( 'network_admin_notices', 'fmwp_incompatible_php_notice' );
	return;
}

// Another copy of this plugin (another folder, or a must-use copy) is already loaded: say so instead of a fatal error.
if ( defined( 'FMWP_PLUGIN_FILE' ) ) {
	if ( ! function_exists( 'fmwp_duplicate_copy_notice' ) ) {
		/**
		 * Explains that two copies of the plugin are installed and which one runs.
		 *
		 * @return void
		 */
		function fmwp_duplicate_copy_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: path of the copy of the plugin that is running. */
					__( 'Two copies of Founders Migration Website are installed. Only the one in %s runs; deactivate and delete the other copy on the Plugins screen.', 'founders-migration-website' ),
					dirname( plugin_basename( FMWP_PLUGIN_FILE ) )
				)
			);
			echo '</p></div>';
		}
		add_action( 'admin_notices', 'fmwp_duplicate_copy_notice' );
		add_action( 'network_admin_notices', 'fmwp_duplicate_copy_notice' );
	}
	return;
}

define( 'FMWP_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/loader.php';

register_activation_hook( __FILE__, array( 'Founders\\Migration\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Founders\\Migration\\Plugin', 'deactivate' ) );

Founders\Migration\Plugin::boot();
