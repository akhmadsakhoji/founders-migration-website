<?php
/**
 * Founders Migration Website
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes what the
 * plugin keeps besides backups: its settings in the storage folder (cloud
 * storages with their sealed credentials, schedules, pull keys), job state
 * and logs, unfinished browser uploads, transients and the scheduler's
 * WP-Cron event.
 *
 * Backups are never deleted. Only files and folders this plugin creates are
 * removed, recognised by their names and contents, and nothing in, around or
 * under the backups folder is touched, wherever FMWP_STORAGE_PATH and
 * FMWP_BACKUPS_PATH point.
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem may need credentials, which uninstall cannot ask for; these are the plugin's own files.

require_once __DIR__ . '/loader.php';

/**
 * A path with forward slashes and no trailing slash.
 *
 * @param mixed $path Path.
 * @return string
 */
function fmwp_uninstall_path( $path ) {
	return rtrim( str_replace( '\\', '/', (string) $path ), '/' );
}

/**
 * Whether $path is $dir or inside it.
 *
 * @param string $path Path.
 * @param string $dir  Folder.
 * @return bool
 */
function fmwp_uninstall_within( $path, $dir ) {
	return '' !== $dir && ( $path === $dir || 0 === strpos( $path . '/', $dir . '/' ) );
}

/**
 * Deletes a file, or a folder with everything in it. Links are removed, never followed.
 * Refuses the backups folder and anything that holds it (job and upload folders are
 * matched by name, so a storage folder that is also the backups folder still loses them).
 *
 * @param string $path    Path.
 * @param string $backups Backups folder.
 * @return void
 */
function fmwp_uninstall_remove( $path, $backups ) {
	if ( fmwp_uninstall_within( $backups, $path ) ) {
		return;
	}
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( (array) @scandir( $path ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
		if ( '.' !== $entry && '..' !== $entry ) {
			fmwp_uninstall_remove( $path . '/' . $entry, $backups );
		}
	}
	@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
}

/**
 * Deletes one regular file (never a link or a folder).
 *
 * @param string $file File.
 * @return void
 */
function fmwp_uninstall_unlink( $file ) {
	if ( is_file( $file ) && ! is_link( $file ) ) {
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
	}
}

/**
 * Deletes the entries of $dir whose names match $pattern (job or upload folders), then $dir if it is empty.
 *
 * @param string $dir     Folder.
 * @param string $pattern Regular expression for entry names.
 * @param string $backups Backups folder.
 * @return void
 */
function fmwp_uninstall_remove_matching( $dir, $pattern, $backups ) {
	if ( is_link( $dir ) || ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) @scandir( $dir ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
		if ( 1 === preg_match( $pattern, (string) $entry ) && is_dir( $dir . '/' . $entry ) && ! is_link( $dir . '/' . $entry ) ) {
			fmwp_uninstall_remove( $dir . '/' . $entry, $backups );
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Kept when something else is in it.
}

$fmwp_storage = fmwp_uninstall_path( defined( 'FMWP_STORAGE_PATH' ) ? FMWP_STORAGE_PATH : WP_CONTENT_DIR . '/fmw-storage' );
$fmwp_backups = fmwp_uninstall_path( defined( 'FMWP_BACKUPS_PATH' ) ? FMWP_BACKUPS_PATH : WP_CONTENT_DIR . '/fmw-backups' );
$fmwp_uploads = wp_upload_dir( null, false );
$fmwp_shared  = array( fmwp_uninstall_path( ABSPATH ), fmwp_uninstall_path( WP_CONTENT_DIR ), fmwp_uninstall_path( $fmwp_uploads['basedir'] ) );

if ( '' !== $fmwp_storage && is_dir( $fmwp_storage ) && ! is_link( $fmwp_storage ) ) {
	// A storage folder that is one of WordPress's own folders keeps everything but the settings.
	$fmwp_not_shared = ! in_array( $fmwp_storage, $fmwp_shared, true );

	// Settings: only files in the plugin's own format, with their lock and unfinished temporary copies.
	foreach ( array(
		'storages'  => 'storages',
		'schedules' => 'schedules',
		'pull-keys' => 'keys',
	) as $fmwp_name => $fmwp_key ) {
		$fmwp_file = $fmwp_storage . '/' . $fmwp_name . '.json';
		$fmwp_data = is_file( $fmwp_file ) ? json_decode( (string) file_get_contents( $fmwp_file ), true ) : null;
		if ( is_array( $fmwp_data ) && isset( $fmwp_data['version'] ) && array_key_exists( $fmwp_key, $fmwp_data ) ) {
			fmwp_uninstall_unlink( $fmwp_file );
		}
		foreach ( (array) glob( $fmwp_file . '.*', GLOB_NOSORT ) as $fmwp_extra ) {
			if ( 1 === preg_match( '/\.json\.(lock|[0-9a-f]{8}\.tmp)$/', (string) $fmwp_extra ) ) {
				fmwp_uninstall_unlink( (string) $fmwp_extra );
			}
		}
	}

	if ( $fmwp_not_shared ) {
		// Job state and logs (jobs/<job ID>/), unfinished browser uploads (uploads/<32 hex>/).
		fmwp_uninstall_remove_matching( $fmwp_storage . '/jobs', '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $fmwp_backups );
		fmwp_uninstall_remove_matching( $fmwp_storage . '/uploads', '/^[a-f0-9]{32}$/', $fmwp_backups );
		fmwp_uninstall_unlink( $fmwp_storage . '/schedules.tick.lock' );
	}

	if ( $fmwp_not_shared && $fmwp_storage !== $fmwp_backups ) {
		// The folder's protection files, when they are still the plugin's own (the backups folder keeps its own).
		foreach ( Founders\Migration\Storage\Paths::protection_files() as $fmwp_name => $fmwp_contents ) {
			$fmwp_file = $fmwp_storage . '/' . $fmwp_name;
			if ( is_file( $fmwp_file ) && ! is_link( $fmwp_file ) && ( file_get_contents( $fmwp_file ) === $fmwp_contents || Founders\Migration\Storage\Paths::is_outdated( $fmwp_file, $fmwp_contents ) ) ) {
				fmwp_uninstall_remove( $fmwp_file, $fmwp_backups );
			}
		}

		@rmdir( $fmwp_storage ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Kept when something else is in it.
	}
}

// Transients: Google sign-in states and errors, pull throttling, the exposure check.
delete_site_transient( 'fmwp_exposure_check' );
global $wpdb;
foreach ( array( '_transient_fmwp_', '_transient_timeout_fmwp_', '_site_transient_fmwp_', '_site_transient_timeout_fmwp_' ) as $fmwp_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $fmwp_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transients with dynamic names; no API lists them.
	if ( is_multisite() ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $fmwp_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As above.
	}
}
delete_site_option( 'fmwp_purge_page_cache' );
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'notoptions', 'options' );

// The scheduler's event: on every site, since development versions could add it to subsites too.
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $fmwp_site_id ) {
		switch_to_blog( (int) $fmwp_site_id );
		wp_clear_scheduled_hook( 'fmwp_schedule_tick' );
		restore_current_blog();
	}
} else {
	wp_clear_scheduled_hook( 'fmwp_schedule_tick' );
}
