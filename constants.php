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

// Plugin.
define( 'FMWP_VERSION', '1.0.1' );
define( 'FMWP_PATH', __DIR__ );
define( 'FMWP_BASENAME', plugin_basename( FMWP_PLUGIN_FILE ) );
define( 'FMWP_URL', plugin_dir_url( FMWP_PLUGIN_FILE ) );

// Archive format.
define( 'FMWP_FORMAT_VERSION', 1 );
define( 'FMWP_ARCHIVE_EXTENSION', 'fmw' );

// Requirements.
define( 'FMWP_MIN_PHP', '7.4' );
define( 'FMWP_MIN_WP', '6.0' );
define( 'FMWP_RECOMMENDED_PHP', '8.3' );

// Part sizes (bytes, uncompressed).
define( 'FMWP_DEFAULT_PART_SIZE', 1073741824 ); // 1 GiB.
define( 'FMWP_MIN_PART_SIZE', 134217728 );      // 128 MiB.
define( 'FMWP_MAX_PART_SIZE', 4294967296 );     // 4 GiB.

/*
 * Data locations. Both can be moved outside the web root from wp-config.php:
 *
 *     define( 'FMWP_BACKUPS_PATH', '/home/example.com/fmw-backups' );
 *     define( 'FMWP_STORAGE_PATH', '/home/example.com/fmw-storage' );
 */
if ( ! defined( 'FMWP_BACKUPS_PATH' ) ) {
	define( 'FMWP_BACKUPS_PATH', WP_CONTENT_DIR . '/fmw-backups' );
}

if ( ! defined( 'FMWP_STORAGE_PATH' ) ) {
	define( 'FMWP_STORAGE_PATH', WP_CONTENT_DIR . '/fmw-storage' );
}
