<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Server requirement checks.
 */
final class Requirements {

	const REQUIRED_EXTENSIONS    = array( 'zlib', 'hash', 'mysqli', 'json' );
	const RECOMMENDED_EXTENSIONS = array( 'openssl', 'pcntl' );

	/**
	 * Blocking problems. The plugin does not run while any are present.
	 *
	 * @return string[]
	 */
	public static function errors(): array {
		global $wp_version;

		$errors = array();

		if ( PHP_INT_SIZE < 8 ) {
			$errors[] = __( 'PHP is running in 32-bit mode, which cannot handle files larger than 2 GB. 64-bit PHP is required.', 'founders-migration-website' );
		}

		foreach ( self::REQUIRED_EXTENSIONS as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				/* translators: %s: PHP extension name. */
				$errors[] = sprintf( __( 'The PHP extension "%s" is required.', 'founders-migration-website' ), $extension );
			}
		}

		if ( extension_loaded( 'zlib' ) && ! function_exists( 'deflate_init' ) ) {
			$errors[] = __( 'The PHP zlib extension is missing incremental compression support (deflate_init).', 'founders-migration-website' );
		}

		if ( isset( $wp_version ) && version_compare( $wp_version, FMWP_MIN_WP, '<' ) ) {
			/* translators: %s: minimum WordPress version. */
			$errors[] = sprintf( __( 'WordPress %s or newer is required.', 'founders-migration-website' ), FMWP_MIN_WP );
		}

		return $errors;
	}

	/**
	 * Non-blocking suggestions.
	 *
	 * @return string[]
	 */
	public static function recommendations(): array {
		$notes = array();

		if ( version_compare( PHP_VERSION, FMWP_RECOMMENDED_PHP, '<' ) ) {
			/* translators: 1: current PHP version, 2: recommended PHP version. */
			$notes[] = sprintf( __( 'PHP %1$s works, but PHP %2$s or newer is recommended.', 'founders-migration-website' ), PHP_VERSION, FMWP_RECOMMENDED_PHP );
		}

		if ( ! extension_loaded( 'openssl' ) ) {
			$notes[] = __( 'The PHP extension "openssl" is needed for password-protected backups.', 'founders-migration-website' );
		}

		if ( 'cli' === PHP_SAPI && ! extension_loaded( 'pcntl' ) ) {
			$notes[] = __( 'The PHP extension "pcntl" lets WP-CLI jobs stop cleanly on Ctrl+C.', 'founders-migration-website' );
		}

		return $notes;
	}
}
