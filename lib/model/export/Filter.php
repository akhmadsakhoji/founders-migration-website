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

defined( 'ABSPATH' ) || exit;

/**
 * Decides which paths under wp-content go into a backup.
 *
 * Paths are relative to wp-content, with forward slashes and no leading
 * slash. Options mirror `wp ai1wm backup`:
 *
 * - exclude: list of cache, media, themes, inactive-themes, muplugins,
 *   plugins, inactive-plugins.
 * - exclude_paths: fnmatch() patterns, for example "uploads/old/*".
 * - active_plugins: plugin folder (or single-file) names that stay with
 *   inactive-plugins.
 * - active_themes: theme folder names that stay with inactive-themes.
 * - skip_paths: extra relative folders that are never included (the FMW
 *   backups and storage folders when they live inside wp-content).
 */
final class Filter {

	/**
	 * Folders never worth backing up: WordPress update scratch space, and All-in-One WP Migration's backups.
	 */
	const ALWAYS_SKIP = array( 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups' );

	/**
	 * Top-level cache folders removed by --exclude-cache.
	 */
	const CACHE_DIRS = array( 'cache', 'et-cache', 'litespeed' );

	/**
	 * Extensions of files that are already compressed and go into .tar parts.
	 */
	const STORED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'mov', 'webm', 'mkv', 'mp3', 'm4a', 'ogg', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'woff', 'woff2', 'pdf' );

	/**
	 * Enabled exclusion names.
	 *
	 * @var array<string,bool>
	 */
	private $exclude;

	/**
	 * Patterns.
	 *
	 * @var string[]
	 */
	private $patterns;

	/**
	 * Active plugin names.
	 *
	 * @var array<string,bool>
	 */
	private $active_plugins;

	/**
	 * Active theme names.
	 *
	 * @var array<string,bool>
	 */
	private $active_themes;

	/**
	 * Folders always skipped.
	 *
	 * @var string[]
	 */
	private $skip;

	/**
	 * Lowercase extensions stored without compression.
	 *
	 * @var array<string,bool>
	 */
	private $stored;

	/**
	 * Builds a filter from job options.
	 *
	 * @param array<string,mixed> $options Job options.
	 */
	public function __construct( array $options ) {
		$this->exclude        = array_fill_keys( self::strings( $options['exclude'] ?? array() ), true );
		$this->patterns       = self::strings( $options['exclude_paths'] ?? array() );
		$this->active_plugins = array_fill_keys( self::strings( $options['active_plugins'] ?? array() ), true );
		$this->active_themes  = array_fill_keys( self::strings( $options['active_themes'] ?? array() ), true );
		$this->skip           = array_merge( self::ALWAYS_SKIP, self::strings( $options['skip_paths'] ?? array() ) );
		$this->stored         = array_fill_keys( self::strings( $options['stored_extensions'] ?? self::STORED_EXTENSIONS ), true );
	}

	/**
	 * Whether a path (file or folder) is excluded.
	 *
	 * @param string $path   Relative path.
	 * @param bool   $is_dir Whether the path is a folder.
	 * @return bool
	 */
	public function excludes( string $path, bool $is_dir ): bool {
		$parts = explode( '/', $path );
		$top   = $parts[0];
		$depth = count( $parts );

		foreach ( $this->skip as $skip ) {
			if ( $path === $skip || 0 === strpos( $path, rtrim( $skip, '/' ) . '/' ) ) {
				return true;
			}
		}

		if ( isset( $this->exclude['cache'] ) && in_array( $top, self::CACHE_DIRS, true ) && ( $depth > 1 || $is_dir ) ) {
			return true;
		}
		if ( isset( $this->exclude['media'] ) && ( 'uploads' === $top || 'blogs.dir' === $top ) && ( $depth > 1 || $is_dir ) ) {
			return true;
		}
		if ( isset( $this->exclude['themes'] ) && 'themes' === $top && ( $depth > 1 || $is_dir ) ) {
			return true;
		}
		if ( isset( $this->exclude['inactive-themes'] ) && 'themes' === $top && $depth > 1 && ( $depth > 2 || $is_dir ) && ! isset( $this->active_themes[ $parts[1] ] ) ) {
			return true;
		}
		if ( isset( $this->exclude['muplugins'] ) && 'mu-plugins' === $top && ( $depth > 1 || $is_dir ) ) {
			return true;
		}
		if ( isset( $this->exclude['plugins'] ) && 'plugins' === $top && ( $depth > 1 || $is_dir ) ) {
			return true;
		}
		if ( isset( $this->exclude['inactive-plugins'] ) && 'plugins' === $top && $depth > 1 && ! isset( $this->active_plugins[ $parts[1] ] ) && 'index.php' !== $parts[1] ) {
			return true;
		}

		foreach ( $this->patterns as $pattern ) {
			if ( fnmatch( $pattern, $path ) || ( $is_dir && fnmatch( $pattern, $path . '/' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a file goes to an uncompressed .tar part.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public function is_stored( string $path ): bool {
		$dot = strrpos( $path, '.' );
		return false !== $dot && isset( $this->stored[ strtolower( substr( $path, $dot + 1 ) ) ] );
	}

	/**
	 * Non-empty strings from an option value.
	 *
	 * @param mixed $value Option value.
	 * @return string[]
	 */
	private static function strings( $value ): array {
		$out = array();
		foreach ( (array) $value as $item ) {
			$item = is_string( $item ) ? trim( $item, " \n\r\t\v\0" ) : '';
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
