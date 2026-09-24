<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Export;

use Founders\Migration\Archive\FmwCrypto;
use Founders\Migration\Job\Secrets;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Turns `wp fmw backup` flags (named like `wp ai1wm backup`) into backup job options.
 *
 * Everything site-specific is resolved here, once, so the steps and a
 * resumed job never depend on WordPress state. No credentials are stored.
 */
final class BackupOptions {

	/**
	 * Flag => exclusion name used by Filter / DatabaseStep.
	 */
	const MIN_PASSWORD = 8;

	const EXCLUDE_FLAGS = array(
		'exclude-spam-comments'    => 'spam-comments',
		'exclude-post-revisions'   => 'post-revisions',
		'exclude-transients'       => 'transients',
		'exclude-media'            => 'media',
		'exclude-themes'           => 'themes',
		'exclude-inactive-themes'  => 'inactive-themes',
		'exclude-muplugins'        => 'muplugins',
		'exclude-plugins'          => 'plugins',
		'exclude-inactive-plugins' => 'inactive-plugins',
		'exclude-cache'            => 'cache',
	);

	/**
	 * Builds job options.
	 *
	 * @param array<string,mixed> $flags WP-CLI associative arguments.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On an invalid flag value.
	 */
	public static function from_flags( array $flags ): array {
		global $wpdb;

		$exclude = array();
		foreach ( self::EXCLUDE_FLAGS as $flag => $name ) {
			if ( ! empty( $flags[ $flag ] ) ) {
				$exclude[] = $name;
			}
		}

		$part_size = isset( $flags['part-size'] ) ? self::parse_size( (string) $flags['part-size'] ) : FMWP_DEFAULT_PART_SIZE;
		if ( $part_size < FMWP_MIN_PART_SIZE || $part_size > FMWP_MAX_PART_SIZE ) {
			throw new \InvalidArgumentException( '--part-size must be between 128M and 4G.' );
		}

		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$skip    = array();
		foreach ( array( fmwp_backups_path(), fmwp_storage_path() ) as $path ) {
			if ( 0 === strpos( $path . '/', $content . '/' ) ) {
				$skip[] = substr( $path, strlen( $content ) + 1 );
			}
		}

		$site     = self::site();
		$password = isset( $flags['password'] ) && is_string( $flags['password'] ) ? $flags['password'] : '';
		if ( isset( $flags['password'] ) && strlen( $password ) < self::MIN_PASSWORD ) {
			throw new \InvalidArgumentException( sprintf( 'The backup password must be at least %d characters long.', self::MIN_PASSWORD ) );
		}

		$options = array(
			'content_dir'      => $content,
			'skip_paths'       => $skip,
			'exclude'          => $exclude,
			'exclude_database' => ! empty( $flags['exclude-database'] ),
			'exclude_tables'   => self::csv( $flags['exclude-tables'] ?? '' ),
			'exclude_paths'    => self::csv( $flags['exclude-paths'] ?? '' ),
			'active_plugins'   => self::active_plugin_names(),
			'active_themes'    => array_values( array_unique( array( get_template(), get_stylesheet() ) ) ),
			'table_prefix'     => (string) $wpdb->base_prefix,
			'part_size'        => $part_size,
			'archive_dir'      => fmwp_backups_path(),
			'archive_name'     => self::archive_name( (string) $site['home_url'] ),
			'generator'        => 'fmw/' . FMWP_VERSION,
			'site'             => $site,
		);
		if ( '' !== $password ) {
			// Format v1, section 7: the manifest is encrypted and the file name names no site.
			$options['encrypt']         = true;
			$options['kdf_iterations']  = FmwCrypto::ITERATIONS;
			$options['secret_password'] = Secrets::seal( $password );
			$options['archive_name']    = sprintf( 'backup-%s-%s.%s', wp_date( 'Ymd-His' ), bin2hex( random_bytes( 3 ) ), FMWP_ARCHIVE_EXTENSION );
		}
		return $options;
	}

	/**
	 * Site facts recorded in the manifest.
	 *
	 * @return array<string,mixed>
	 */
	public static function site(): array {
		global $wpdb, $wp_version;

		$abspath = trailingslashit( wp_normalize_path( ABSPATH ) );
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$uploads = wp_upload_dir( null, false );
		$uploads = untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
		$server  = (string) $wpdb->db_server_info();

		$sites = array();
		if ( is_multisite() ) {
			foreach ( get_sites( array( 'number' => 0 ) ) as $blog ) {
				$sites[] = array(
					'blog_id' => (int) $blog->blog_id,
					'domain'  => (string) $blog->domain,
					'path'    => (string) $blog->path,
				);
			}
		}

		return array(
			'home_url'       => home_url(),
			'site_url'       => site_url(),
			'abspath'        => $abspath,
			'content_dir'    => self::relative( $content, $abspath ),
			'uploads_dir'    => self::relative( $uploads, $abspath ),
			'table_prefix'   => (string) $wpdb->base_prefix,
			'multisite'      => is_multisite(),
			'sites'          => $sites,
			'wp_version'     => (string) $wp_version,
			'php_version'    => PHP_VERSION,
			'db'             => array(
				'engine'  => false !== stripos( $server, 'mariadb' ) ? 'mariadb' : 'mysql',
				'version' => $server,
				'charset' => (string) $wpdb->charset,
				'collate' => (string) $wpdb->collate,
			),
			'active_plugins' => array_values( (array) get_option( 'active_plugins', array() ) ),
			'template'       => get_template(),
			'stylesheet'     => get_stylesheet(),
		);
	}

	/**
	 * Archive file name: <domain>-<YYYYMMDD>-<HHMMSS>-<token>.fmw.
	 *
	 * @param string $home_url Home URL.
	 * @return string
	 */
	public static function archive_name( string $home_url ): string {
		$host = (string) wp_parse_url( $home_url, PHP_URL_HOST );
		$path = trim( (string) wp_parse_url( $home_url, PHP_URL_PATH ), '/' );
		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9.-]+/', '-', $host . ( '' === $path ? '' : '-' . $path ) ) );
		$slug = trim( $slug, '-.' );
		return sprintf( '%s-%s-%s.%s', '' === $slug ? 'site' : $slug, wp_date( 'Ymd-His' ), bin2hex( random_bytes( 3 ) ), FMWP_ARCHIVE_EXTENSION );
	}

	/**
	 * Parses 512M, 1G, 1.5G or a byte count.
	 *
	 * @param string $value Size.
	 * @return int
	 */
	public static function parse_size( string $value ): int {
		$value = strtoupper( trim( $value, " \n\r\t\v\0" ) );
		$units = array(
			'K' => 1024,
			'M' => 1048576,
			'G' => 1073741824,
		);
		$unit  = substr( $value, -1 );
		if ( isset( $units[ $unit ] ) ) {
			return (int) round( (float) substr( $value, 0, -1 ) * $units[ $unit ] );
		}
		return (int) $value;
	}

	/**
	 * Plugin folder names (or single-file names) that are active on this site or network-wide.
	 *
	 * @return string[]
	 */
	private static function active_plugin_names(): array {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$names = array();
		foreach ( $plugins as $plugin ) {
			$plugin  = (string) $plugin;
			$slash   = strpos( $plugin, '/' );
			$names[] = false === $slash ? $plugin : substr( $plugin, 0, $slash );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Comma-separated flag value as a list.
	 *
	 * @param mixed $value Flag value.
	 * @return string[]
	 */
	private static function csv( $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$items = array();
		foreach ( explode( ',', $value ) as $item ) {
			$item = trim( $item, " \n\r\t\v\0" );
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Path relative to the WordPress root, or the absolute path when outside it.
	 *
	 * @param string $path    Absolute path.
	 * @param string $abspath WordPress root with trailing slash.
	 * @return string
	 */
	private static function relative( string $path, string $abspath ): string {
		return 0 === strpos( $path . '/', $abspath ) ? substr( $path, strlen( $abspath ) ) : $path;
	}
}
