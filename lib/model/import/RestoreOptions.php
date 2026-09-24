<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Import;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Builds restore job options for the current WordPress site. No credentials are stored.
 */
final class RestoreOptions {

	/**
	 * Job options.
	 *
	 * @param string              $archive Archive path.
	 * @param array<string,mixed> $flags   WP-CLI associative arguments.
	 * @return array<string,mixed>
	 */
	public static function build( string $archive, array $flags ): array {
		global $wpdb;

		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$protect = array();
		$paths   = array(
			wp_normalize_path( FMWP_PATH ),
			$content . '/plugins/' . dirname( FMWP_BASENAME ), // Same folder when plugins are symlinked.
			fmwp_backups_path(),
			fmwp_storage_path(),
		);
		foreach ( array_unique( $paths ) as $path ) {
			if ( 0 === strpos( $path . '/', $content . '/' ) ) {
				$protect[] = substr( $path, strlen( $content ) + 1 );
			}
		}

		return array(
			'archive'            => $archive,
			'target'             => array(
				'home_url'     => home_url(),
				'site_url'     => site_url(),
				'abspath'      => trailingslashit( wp_normalize_path( ABSPATH ) ),
				'content_dir'  => $content,
				'table_prefix' => (string) $wpdb->base_prefix,
				'multisite'    => is_multisite(),
			),
			'protect_paths'      => $protect,
			'email_replace'      => empty( $flags['exclude-email-replace'] ),
			'keep_old_tables'    => ! empty( $flags['keep-old-tables'] ),
			'skip_space_check'   => ! empty( $flags['skip-space-check'] ),
			'keep_active_plugin' => FMWP_BASENAME,
		);
	}
}
