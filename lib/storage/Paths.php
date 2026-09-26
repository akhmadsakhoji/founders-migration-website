<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Small protective files written directly; WP_Filesystem may need credentials.

/**
 * Creates the data folders and protects them from direct web access.
 *
 * Protection never relies on .htaccess alone: OpenLiteSpeed and Nginx
 * usually ignore it. Folders also get index.php and web.config, backup names
 * carry a random token, and exposure_check() probes the folder over HTTP.
 */
final class Paths {

	const EXPOSURE_TRANSIENT = 'fmwp_exposure_check';
	const CANARY_FILE        = 'fmw-canary.txt';

	/** First line of the .htaccess FMW writes; a file starting with it is FMW's and is kept up to date. */
	const HTACCESS_MARKER = '# Founders Migration Website: deny direct web access.';

	/**
	 * Creates both data folders with their protective files.
	 *
	 * @return bool Whether both folders exist and are writable.
	 */
	public static function ensure_all(): bool {
		$backups = self::ensure_protected( fmwp_backups_path() );
		$storage = self::ensure_protected( fmwp_storage_path() );
		return $backups && $storage;
	}

	/**
	 * The files that keep a data folder closed to the web, by name, with their contents.
	 *
	 * @return array<string,string>
	 */
	public static function protection_files(): array {
		return array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => implode(
				"\n",
				array(
					self::HTACCESS_MARKER,
					'# The rewrite rule is what OpenLiteSpeed and LiteSpeed read; Apache uses either.',
					'<IfModule mod_rewrite.c>',
					'	RewriteEngine On',
					'	RewriteRule .* - [F,L]',
					'</IfModule>',
					'<IfModule mod_authz_core.c>',
					'	Require all denied',
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					'	Order deny,allow',
					'	Deny from all',
					'</IfModule>',
					'',
				)
			),
			'web.config' => implode(
				"\n",
				array(
					'<?xml version="1.0" encoding="UTF-8"?>',
					'<configuration>',
					'	<system.webServer>',
					'		<security>',
					'			<authorization>',
					'				<remove users="*" roles="" verbs="" />',
					'				<add accessType="Deny" users="*" />',
					'			</authorization>',
					'		</security>',
					'	</system.webServer>',
					'</configuration>',
					'',
				)
			),
		);
	}

	/**
	 * Creates $dir if needed and drops the protective files into it.
	 *
	 * @param string $dir Directory.
	 * @return bool Whether the directory exists and is writable.
	 */
	public static function ensure_protected( string $dir ): bool {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$files = self::protection_files();

		foreach ( $files as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents );
			} elseif ( self::is_outdated( $path, $contents ) && false !== file_put_contents( $path, $contents ) ) {
				delete_site_transient( self::EXPOSURE_TRANSIENT ); // Check the folder again with the new rules.
			}
		}

		return wp_is_writable( $dir );
	}

	/**
	 * Whether $path is the .htaccess an older FMW version wrote, unchanged (a file edited or
	 * written by someone else is left alone).
	 *
	 * @param string $path     File.
	 * @param string $contents Current contents.
	 * @return bool
	 */
	public static function is_outdated( string $path, string $contents ): bool {
		if ( '.htaccess' !== basename( $path ) || ! is_file( $path ) || is_link( $path ) ) {
			return false;
		}
		$existing = str_replace( "\r\n", "\n", (string) file_get_contents( $path ) );
		$previous = array(
			// Up to 1.0.1: Apache access rules only, which OpenLiteSpeed ignores.
			implode( "\n", array( self::HTACCESS_MARKER, '<IfModule mod_authz_core.c>', '	Require all denied', '</IfModule>', '<IfModule !mod_authz_core.c>', '	Order deny,allow', '	Deny from all', '</IfModule>', '' ) ),
		);
		return $existing !== $contents && in_array( $existing, $previous, true );
	}

	/**
	 * Checks over HTTP whether the backups folder can be read from the web.
	 *
	 * Only applies when the folder lives inside wp-content. The result is
	 * cached for a day.
	 *
	 * @param bool $force Ignore the cached result.
	 * @return string|null 'exposed', 'protected', or null when not applicable or unknown.
	 */
	public static function exposure_check( bool $force = false ): ?string {
		$dir     = fmwp_backups_path();
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		if ( 0 !== strpos( $dir . '/', $content . '/' ) ) {
			return null; // Outside wp-content: not reachable through content_url().
		}

		if ( ! $force ) {
			$cached = get_site_transient( self::EXPOSURE_TRANSIENT );
			if ( is_string( $cached ) ) {
				return $cached;
			}
		}

		$token  = wp_generate_password( 32, false );
		$canary = $dir . '/' . self::CANARY_FILE;
		if ( false === file_put_contents( $canary, $token ) ) {
			return null;
		}

		$url      = content_url( substr( $dir, strlen( $content ) ) . '/' . self::CANARY_FILE );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 3, // Follow http -> https and www redirects.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests to this same site.
			)
		);
		wp_delete_file( $canary );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code && false !== strpos( wp_remote_retrieve_body( $response ), $token ) ) {
			$result = 'exposed';
		} elseif ( 200 === $code || in_array( $code, array( 401, 403, 404, 410 ), true ) ) {
			$result = 'protected';
		} else {
			return null; // Inconclusive (server error, redirect loop, ...): try again next time.
		}

		set_site_transient( self::EXPOSURE_TRANSIENT, $result, DAY_IN_SECONDS );

		return $result;
	}
}
