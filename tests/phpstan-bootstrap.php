<?php
/**
 * Founders Migration Website
 *
 * Constants PHPStan needs to know about (normally defined at runtime by WordPress and constants.php).
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'FMWP_PLUGIN_FILE', dirname( __DIR__ ) . '/founders-migration-website.php' );
define( 'FMWP_VERSION', '0.0.0' );
define( 'FMWP_PATH', dirname( __DIR__ ) );
define( 'FMWP_BASENAME', 'founders-migration-website/founders-migration-website.php' );
define( 'FMWP_URL', 'https://example.com/wp-content/plugins/founders-migration-website/' );
define( 'FMWP_FORMAT_VERSION', 1 );
define( 'FMWP_ARCHIVE_EXTENSION', 'fmw' );
define( 'FMWP_MIN_PHP', '7.4' );
define( 'FMWP_MIN_WP', '6.0' );
define( 'FMWP_RECOMMENDED_PHP', '8.3' );
define( 'FMWP_DEFAULT_PART_SIZE', 1073741824 );
define( 'FMWP_MIN_PART_SIZE', 134217728 );
define( 'FMWP_MAX_PART_SIZE', 4294967296 );
define( 'FMWP_BACKUPS_PATH', WP_CONTENT_DIR . '/fmw-backups' );
define( 'FMWP_STORAGE_PATH', WP_CONTENT_DIR . '/fmw-storage' );
