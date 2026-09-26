<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Reset;

use Founders\Migration\Model\Import\RestoreOptions;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Builds reset job options for the current WordPress site, or for one site of a network.
 *
 * A reset can empty the database (a fresh WordPress install that keeps the
 * site address, language, time zone and the chosen administrators), the
 * media library, the plugins (except this one) and the themes (except the
 * active one). Must-use plugins, drop-ins and wp-config.php are never touched.
 *
 * On a network one site is reset at a time (not the main site): its own
 * tables (wp_<id>_*) and its media (uploads/sites/<id>/). Users are shared:
 * they stay, but only the chosen ones keep a role on the site (administrator).
 * Plugins and themes are shared too, so they are not reset per site.
 *
 * Or the whole network is reset (reset_network): a fresh network at the same
 * address with only its main site, the chosen users as super admins, and
 * every other site, user and setting gone; plugins and themes can go too.
 */
final class ResetOptions {

	const PARTS = array( 'database', 'media', 'plugins', 'themes' );

	/**
	 * Job options.
	 *
	 * @param string[] $parts      Parts to reset (see PARTS).
	 * @param int[]    $keep_users IDs of the users to keep as administrators (database reset).
	 * @param bool     $keep_old   Keep the replaced tables as fmwold_*.
	 * @param int      $site       On a network: the site to reset.
	 * @param bool     $network    On a network: reset all of it.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On an unknown part, nothing to reset, a site or network that cannot be reset, or no user to keep.
	 */
	public static function build( array $parts, array $keep_users, bool $keep_old = false, int $site = 0, bool $network = false ): array {
		if ( $network ) {
			self::check_network( $site );
		} elseif ( is_multisite() ) {
			self::check_site( $site );
			if ( array_intersect( array( 'plugins', 'themes' ), $parts ) ) {
				throw new \InvalidArgumentException( 'Plugins and themes are shared by every site of the network: they are not reset for one site. Deactivate or delete them in Network Admin.' );
			}
		} elseif ( $site > 0 ) {
			throw new \InvalidArgumentException( '--site is for multisite networks.' );
		}
		$unknown = array_diff( $parts, self::PARTS );
		if ( $unknown ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown reset part: %s.', implode( ', ', $unknown ) ) );
		}
		$parts = array_values( array_intersect( self::PARTS, $parts ) );
		if ( ! $parts ) {
			throw new \InvalidArgumentException( 'Choose what to reset: database, media, plugins and/or themes.' );
		}

		$keep_users = array_values( array_unique( array_filter( array_map( 'intval', $keep_users ) ) ) );
		if ( in_array( 'database', $parts, true ) ) {
			$keep_users = array_values(
				array_filter(
					$keep_users,
					static function ( int $id ): bool {
						return false !== get_userdata( $id );
					}
				)
			);
			if ( defined( 'CUSTOM_USER_TABLE' ) || defined( 'CUSTOM_USER_META_TABLE' ) ) {
				throw new \InvalidArgumentException( 'This site shares its users table with other sites (CUSTOM_USER_TABLE); its database cannot be reset.' );
			}
			if ( ! $keep_users ) {
				throw new \InvalidArgumentException( 'At least one existing user must be kept, or nobody could log in after the reset.' );
			}
		}

		$switch = $site > 0 ? $site : ( $network ? get_main_site_id() : 0 );
		if ( $switch > 0 ) {
			switch_to_blog( $switch ); // Its address, uploads folder and theme (the main site's for a whole network).
		}
		try {
			$restore = RestoreOptions::build( '', array() );
			$active  = array(
				'template'   => get_template(),
				'stylesheet' => get_stylesheet(),
			);
			$themes  = array_values( array_unique( array_values( $active ) ) );
		} finally {
			if ( $switch > 0 ) {
				restore_current_blog();
			}
		}
		if ( $network ) {
			// Every site keeps its theme when only files are reset; new sites always get the default theme.
			$themes = array_values( array_unique( array_merge( in_array( 'database', $parts, true ) ? $themes : self::network_themes(), self::default_theme() ) ) );
		}
		unset( $restore['archive'], $restore['email_replace'], $restore['skip_space_check'] );
		if ( $site > 0 ) {
			$restore['keep_active_network'] = false; // The network's settings are not reset: nothing to put back.
		}
		// The media folder matters only when media is reset (ResetFilesStep checks it again).
		if ( $site > 0 && in_array( 'media', $parts, true ) && ! self::own_uploads( (string) ( $restore['target']['uploads_dir'] ?? '' ), $site ) ) {
			throw new \InvalidArgumentException( sprintf( 'The media folder of site %d (%s) is not its own uploads/sites/%d folder; its media cannot be reset on its own.', $site, (string) ( $restore['target']['uploads_dir'] ?? '?' ), $site ) );
		}

		return array_merge(
			$restore,
			array(
				'reset'              => $parts,
				'reset_site'         => $site,
				'reset_network'      => $network,
				'keep_users'         => $keep_users,
				'keep_themes'        => $themes,
				'active_theme'       => $active, // The fresh (main) site's theme; keep_themes may list more.
				'keep_paths'         => array_values( array_unique( array( wp_normalize_path( FMWP_PATH ), fmwp_backups_path(), fmwp_storage_path() ) ) ), // Wherever they live.
				'keep_old_tables'    => $keep_old,
				'replace_all_tables' => true, // The fresh database replaces every table of this site, plugin tables included.
			)
		);
	}

	/**
	 * What must be typed to confirm a reset: the site's host name (with the port, if any);
	 * for a site of a network its address (example.com/shop).
	 *
	 * @param int $site Site of a network, or 0.
	 * @return string
	 */
	public static function confirm_word( int $site = 0 ): string {
		if ( $site > 0 ) {
			$blog = function_exists( 'get_site' ) ? get_site( $site ) : null;
			return $blog ? strtolower( rtrim( (string) $blog->domain . (string) $blog->path, '/' ) ) : '';
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		return strtolower( $host . ( $port ? ':' . $port : '' ) );
	}

	/**
	 * Whether a typed confirmation matches.
	 *
	 * @param string $typed Typed text.
	 * @param int    $site  Site of a network, or 0.
	 * @return bool
	 */
	public static function confirmed( string $typed, int $site = 0 ): bool {
		$word = self::confirm_word( $site );
		return '' !== $word && hash_equals( $word, rtrim( strtolower( trim( $typed, " \t\n\r\0\x0B" ) ), '/' ) );
	}

	/**
	 * IDs of all administrators (of a site of a network, when given), the default users kept by `wp fmw reset`.
	 *
	 * @param int $site Site of a network, or 0.
	 * @return int[]
	 */
	public static function administrators( int $site = 0 ): array {
		$query = array(
			'role'   => 'administrator',
			'fields' => 'ID',
		);
		if ( $site > 0 ) {
			$query['blog_id'] = $site;
		}
		return array_map( 'intval', get_users( $query ) );
	}

	/**
	 * The site of this network a --site value names (its ID or address), refusing the ones that cannot be reset alone.
	 *
	 * @param string $choice ID, address (example.com/shop, shop.example.com) or URL.
	 * @return int
	 * @throws \InvalidArgumentException When there is no such site, or it is the main site.
	 */
	public static function find_site( string $choice ): int {
		$choice  = strtolower( trim( $choice, " \t\n\r\0\x0B" ) );
		$address = rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $choice ), '/' );
		foreach ( get_sites(
			array(
				'number'  => 0,
				'network' => get_current_network_id(),
			)
		) as $blog ) {
			if ( ( ctype_digit( $choice ) && (int) $choice === (int) $blog->blog_id ) || rtrim( strtolower( $blog->domain . $blog->path ), '/' ) === $address ) {
				$site = (int) $blog->blog_id;
				self::check_site( $site );
				return $site;
			}
		}
		throw new \InvalidArgumentException( sprintf( 'This network has no site "%s".', preg_replace( '/[^\x21-\x7E]/', '?', substr( $choice, 0, 200 ) ) ) );
	}

	/**
	 * Refuses a network that cannot be reset as a whole here.
	 *
	 * @param int $site A site chosen as well (not allowed).
	 * @return void
	 * @throws \InvalidArgumentException When this is no network, a site is chosen too, or the install is not a plain network.
	 */
	private static function check_network( int $site ): void {
		if ( ! is_multisite() ) {
			throw new \InvalidArgumentException( '--network is for multisite networks.' );
		}
		if ( $site > 0 ) {
			throw new \InvalidArgumentException( 'Choose --site=<site> or --network, not both.' );
		}
		if ( count( get_networks( array( 'number' => 2 ) ) ) > 1 ) {
			throw new \InvalidArgumentException( 'This install has several networks; resetting one of them as a whole is not supported. Reset its sites one by one.' );
		}
		if ( 1 !== get_main_site_id() ) {
			throw new \InvalidArgumentException( 'The main site of this network is not site 1; resetting the whole network is not supported. Reset its sites one by one.' );
		}
	}

	/**
	 * Themes any site of this network uses (template and stylesheet), plus the network's default.
	 *
	 * @return string[]
	 */
	private static function network_themes(): array {
		$themes = array();
		foreach ( get_sites(
			array(
				'number'  => 0,
				'fields'  => 'ids',
				'network' => get_current_network_id(),
			)
		) as $id ) {
			$themes[] = (string) get_blog_option( (int) $id, 'template' );
			$themes[] = (string) get_blog_option( (int) $id, 'stylesheet' );
		}
		return array_values( array_unique( array_filter( $themes ) ) );
	}

	/**
	 * The theme new sites get: WP_DEFAULT_THEME, or the newest core default theme when that one is missing.
	 *
	 * @return string[]
	 */
	private static function default_theme(): array {
		if ( defined( 'WP_DEFAULT_THEME' ) && wp_get_theme( WP_DEFAULT_THEME )->exists() ) {
			return array( (string) WP_DEFAULT_THEME );
		}
		$core = method_exists( 'WP_Theme', 'get_core_default_theme' ) ? \WP_Theme::get_core_default_theme() : false; // @phpstan-ignore function.alreadyNarrowedType (WordPress 6.4+; older ones have no such method.)
		return $core ? array( (string) $core->get_stylesheet() ) : array();
	}

	/**
	 * IDs of the network's super admins, the default users kept by `wp fmw reset --network`.
	 *
	 * @return int[]
	 */
	public static function super_admins(): array {
		$ids = array();
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				$ids[] = (int) $user->ID;
			}
		}
		return $ids;
	}

	/**
	 * Refuses a site of this network that cannot be reset alone.
	 *
	 * @param int $site Site ID (0: none chosen).
	 * @return void
	 * @throws \InvalidArgumentException When none is chosen, it does not exist, or it is the main site or site 1.
	 */
	private static function check_site( int $site ): void {
		if ( $site < 1 ) {
			throw new \InvalidArgumentException( 'On a multisite network, choose one site with --site=<id or address>, or the whole network with --network.' );
		}
		$blog = get_site( $site );
		if ( ! $blog || get_current_network_id() !== (int) $blog->network_id ) {
			throw new \InvalidArgumentException( sprintf( 'This network has no site %d.', $site ) );
		}
		if ( is_main_site( $site ) || 1 === $site ) {
			throw new \InvalidArgumentException( 'That is the network\'s main site (its tables hold the network\'s users and settings): it is reset with the whole network (--network).' );
		}
	}

	/**
	 * Whether an uploads folder is a network site's own (uploads/sites/<id>, or blogs.dir/<id>/files on old networks).
	 *
	 * @param string $dir  Folder.
	 * @param int    $site Site ID.
	 * @return bool
	 */
	public static function own_uploads( string $dir, int $site ): bool {
		$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
		foreach ( array( '/sites/' . $site, '/blogs.dir/' . $site . '/files' ) as $own ) {
			if ( strlen( $dir ) > strlen( $own ) && substr( $dir, -strlen( $own ) ) === $own ) {
				return true;
			}
		}
		return false;
	}
}
