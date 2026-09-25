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

use Founders\Migration\Database\Connection;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\JobException;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are backtick-quoted and values escaped by Connection.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress stores these values serialized; unserialize() runs with allowed_classes=false.

/**
 * Restores one site of a multisite network backup as a single site.
 *
 * WordPress names a network's tables by site ID: site 1 has the bare prefix
 * (wp_posts), site 2 wp_2_posts; users and usermeta are shared, and blogs,
 * site, sitemeta and friends describe the network. For the chosen site:
 *
 * - tables: its own become the single site's (wp_2_posts -> wp_posts),
 *   users and usermeta come along, everything else stays in the backup
 *   (sitemeta is read for super admins and network-activated plugins,
 *   then dropped);
 * - users: those with a role on the site or posts on it, plus super
 *   admins (as administrators when they had no role there);
 * - user meta and options: the site's prefixed keys lose their site ID
 *   (wp_2_capabilities -> wp_capabilities, wp_2_user_roles), other sites'
 *   keys go; network-activated plugins become active plugins;
 * - files: uploads/sites/<id>/ becomes uploads/, other sites' media stays
 *   in the backup;
 * - URLs: the site's address and its uploads URLs become this site's;
 *   links to the network's other sites are kept as they are.
 */
final class SubsiteExtract {

	/**
	 * Network tables that a single site does not have.
	 */
	const NETWORK_TABLES = array( 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'sitecategories' );

	/**
	 * Per-site user meta keys WordPress itself writes with the site's prefix (wp_capabilities, wp_2_capabilities).
	 */
	const SITE_USER_KEYS = array( 'capabilities', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id', 'persisted_preferences' );

	/**
	 * Finds the site to restore.
	 *
	 * @param array<string,mixed> $site   Manifest site of a network backup.
	 * @param string              $choice Site ID, address (shop.example.com, example.com/shop) or URL.
	 * @return array{blog_id:int,domain:string,path:string,site_ids:int[],main_site:int,network_id:int,network_domain:string,network_path:string}
	 * @throws JobException When the backup has no such site.
	 */
	public static function resolve( array $site, string $choice ): array {
		$sites = array();
		foreach ( (array) ( $site['sites'] ?? array() ) as $blog ) {
			if ( is_array( $blog ) && (int) ( $blog['blog_id'] ?? 0 ) > 0 ) {
				$path    = trim( (string) ( $blog['path'] ?? '/' ), '/' );
				$sites[] = array(
					'blog_id' => (int) $blog['blog_id'],
					'domain'  => strtolower( (string) ( $blog['domain'] ?? '' ) ),
					'path'    => '' === $path ? '/' : '/' . $path . '/',
				);
			}
		}
		$network = NetworkMove::source( $site );
		$choice  = trim( strtolower( $choice ), " \t\n\r\0\x0B" );
		$address = rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $choice ), '/' );
		foreach ( $sites as $blog ) {
			if ( ( ctype_digit( $choice ) && (int) $choice === $blog['blog_id'] ) || rtrim( $blog['domain'] . $blog['path'], '/' ) === $address ) {
				return $blog + array(
					'site_ids'       => array_column( $sites, 'blog_id' ),
					'main_site'      => $network['main_site'],
					'network_id'     => max( 1, (int) ( $site['network']['id'] ?? 1 ) ),
					'network_domain' => $network['domain'],
					'network_path'   => $network['path'],
				);
			}
		}
		throw new JobException( sprintf( 'The backup has no site "%s". Its sites: %s.', self::printable( $choice ), self::listing( $site ) ) );
	}

	/**
	 * The backup's sites, for messages: "1 example.com/, 2 example.com/shop/" (the first 50).
	 *
	 * @param array<string,mixed> $site Manifest site.
	 * @return string
	 */
	public static function listing( array $site ): string {
		$list  = array();
		$sites = array_values( array_filter( (array) ( $site['sites'] ?? array() ), 'is_array' ) );
		foreach ( array_slice( $sites, 0, 50 ) as $blog ) {
			$list[] = (int) ( $blog['blog_id'] ?? 0 ) . ' ' . self::printable( (string) ( $blog['domain'] ?? '' ) . (string) ( $blog['path'] ?? '' ) );
		}
		if ( count( $sites ) > 50 ) {
			$list[] = sprintf( 'and %d more', count( $sites ) - 50 );
		}
		return implode( ', ', $list );
	}

	/**
	 * Text from a backup, safe for a terminal or log: anything but printable ASCII becomes "?".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function printable( string $text ): string {
		return (string) preg_replace( '/[^\x21-\x7E]/', '?', substr( $text, 0, 300 ) );
	}

	/**
	 * Name (after the prefix) a network table gets in the single site, or null when it stays out.
	 *
	 * @param string $table    Source table.
	 * @param string $prefix   Source base prefix.
	 * @param int    $blog     Chosen site.
	 * @param int[]  $site_ids The network's sites: wp_404_to_301 is no site's table unless there is a site 404.
	 * @return string|null
	 */
	public static function table( string $table, string $prefix, int $blog, array $site_ids ): ?string {
		if ( 0 !== strpos( $table, $prefix ) ) {
			return null;
		}
		$rest = substr( $table, strlen( $prefix ) );
		if ( 1 === preg_match( '/^([1-9][0-9]*)_(.+)$/', $rest, $m ) && in_array( (int) $m[1], $site_ids, true ) ) {
			return (int) $m[1] === $blog ? $m[2] : null; // Another site's table.
		}
		if ( in_array( $rest, array( 'users', 'usermeta', 'sitemeta' ), true ) ) {
			return $rest; // sitemeta is read, then dropped.
		}
		if ( in_array( $rest, self::NETWORK_TABLES, true ) ) {
			return null;
		}
		return 1 === $blog ? $rest : null; // Tables with the bare prefix are site 1's.
	}

	/**
	 * Where a file of the backup goes (relative to wp-content), or null when it stays out.
	 *
	 * The main site's media is in uploads/ itself, every other site's in
	 * uploads/sites/<id>/ (blogs.dir/<id>/files/ on networks from before
	 * WordPress 3.5).
	 *
	 * @param string $relative Path in wp-content.
	 * @param string $uploads  Uploads folder relative to wp-content ("uploads").
	 * @param int    $blog     Chosen site.
	 * @param int    $main     The network's main site.
	 * @return string|null
	 */
	public static function file( string $relative, string $uploads, int $blog, int $main ): ?string {
		$uploads = trim( $uploads, '/' );
		if ( 'blogs.dir' === $relative || 0 === strpos( $relative, 'blogs.dir/' ) ) {
			$own = 'blogs.dir/' . $blog . '/files';
			if ( $blog !== $main && '' !== $uploads && ( $relative === $own || 0 === strpos( $relative, $own . '/' ) ) ) {
				return $uploads . substr( $relative, strlen( $own ) );
			}
			return null;
		}
		if ( '' === $uploads || ( $relative !== $uploads && 0 !== strpos( $relative, $uploads . '/' ) ) ) {
			return $relative; // Themes, plugins and other folders are shared by the network.
		}
		$sites = $uploads . '/sites';
		if ( $relative === $sites || 0 === strpos( $relative, $sites . '/' ) ) {
			$own = $sites . '/' . $blog;
			if ( $blog !== $main && ( $relative === $own || 0 === strpos( $relative, $own . '/' ) ) ) {
				return $uploads . substr( $relative, strlen( $own ) );
			}
			return null;
		}
		return $blog === $main ? $relative : null; // uploads/2026/... is the main site's media.
	}

	/**
	 * Fixes users, user meta and options in the imported tables, in one transaction (see ReplaceStep).
	 *
	 * The tables it changes are made InnoDB first: a MyISAM table would keep
	 * half the changes of an interrupted run, and a second run would then
	 * take the renamed keys for the main site's.
	 *
	 * @param RestoreDatabase     $restore Database helper.
	 * @param array<string,mixed> $plan    From resolve().
	 * @param string              $prefix  Source base prefix.
	 * @param Context             $context Context.
	 * @return void
	 * @throws \Throwable After rolling back, when a query fails.
	 */
	public static function apply( RestoreDatabase $restore, array $plan, string $prefix, Context $context ): void {
		$db     = $restore->db();
		$blog   = (int) $plan['blog_id'];
		$own    = 1 === $blog ? $prefix : $prefix . $blog . '_';
		$tables = array_flip( $restore->imported_tables() );
		$has    = static function ( string $name ) use ( $tables ): bool {
			return isset( $tables[ RestoreDatabase::TMP . $name ] );
		};
		$t      = static function ( string $name ): string {
			return Connection::identifier( RestoreDatabase::TMP . $name );
		};
		foreach ( array( 'users', 'usermeta', 'options' ) as $name ) {
			if ( $has( $name ) ) {
				$engine = $db->column( 'SELECT `ENGINE` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( RestoreDatabase::TMP . $name ) );
				if ( 'innodb' !== strtolower( (string) ( $engine[0] ?? 'innodb' ) ) ) {
					$db->query( 'ALTER TABLE ' . $t( $name ) . ' ENGINE=InnoDB' );
					$context->log( sprintf( 'Converted the %s table from %s to InnoDB, so the changes below are all or nothing.', $name, (string) $engine[0] ) );
				}
			}
		}

		$admins   = array();
		$sitewide = array();
		if ( $has( 'sitemeta' ) ) {
			foreach ( $db->rows( 'SELECT `meta_key`, `meta_value` FROM ' . $t( 'sitemeta' ) . " WHERE `meta_key` IN ('site_admins', 'active_sitewide_plugins') AND `site_id` = " . (int) $plan['network_id'] ) as $row ) {
				$value = unserialize( (string) $row['meta_value'], array( 'allowed_classes' => false ) );
				if ( 'site_admins' === $row['meta_key'] && is_array( $value ) ) {
					$admins = array_values( array_filter( $value, 'is_string' ) );
				} elseif ( is_array( $value ) ) {
					$sitewide = array_values( array_filter( array_keys( $value ), 'is_string' ) );
				}
			}
		}

		$super = array();
		$db->query( 'START TRANSACTION' );
		try {
			$removed = 0;
			if ( $has( 'users' ) && $has( 'usermeta' ) ) {
				// Users with a role here, or whose name is on its posts, comments or links, and super admins.
				$keep = array_map( 'intval', $db->column( 'SELECT DISTINCT `user_id` FROM ' . $t( 'usermeta' ) . ' WHERE `meta_key` = ' . $db->quote( $own . 'capabilities' ) ) );
				foreach ( array(
					'posts'    => 'post_author',
					'comments' => 'user_id',
					'links'    => 'link_owner',
				) as $table => $column ) {
					if ( $has( $table ) ) {
						$keep = array_merge( $keep, array_map( 'intval', $db->column( 'SELECT DISTINCT ' . Connection::identifier( $column ) . ' FROM ' . $t( $table ) ) ) );
					}
				}
				if ( $admins ) {
					$super = array_map( 'intval', $db->column( 'SELECT `ID` FROM ' . $t( 'users' ) . ' WHERE `user_login` IN (' . implode( ', ', array_map( array( $db, 'quote' ), $admins ) ) . ')' ) );
				}
				$list    = implode( ', ', array_values( array_unique( array_merge( $keep, $super, array( 0 ) ) ) ) );
				$gone    = $db->column( 'SELECT COUNT(*) FROM ' . $t( 'users' ) . " WHERE `ID` NOT IN ({$list})" );
				$removed = (int) ( $gone[0] ?? 0 );
				$db->query( 'DELETE FROM ' . $t( 'users' ) . " WHERE `ID` NOT IN ({$list})" );
				$db->query( 'DELETE FROM ' . $t( 'usermeta' ) . " WHERE `user_id` NOT IN ({$list})" );

				$meta = $t( 'usermeta' );
				$like = static function ( string $value ) use ( $db ): string {
					return "'" . $db->escape( addcslashes( $value, '\\%_' ) ) . "%'";
				};
				$db->query( "DELETE FROM {$meta} WHERE `meta_key` IN ('primary_blog', 'source_domain')" );
				foreach ( (array) $plan['site_ids'] as $id ) {
					if ( (int) $id !== $blog && 1 !== (int) $id ) {
						$db->query( "DELETE FROM {$meta} WHERE `meta_key` LIKE " . $like( $prefix . (int) $id . '_' ) ); // Another site's keys.
					}
				}
				if ( 1 !== $blog ) {
					// Site 1's own keys give way to the chosen site's; other keys with the bare prefix are global (wp_2fa_...) and stay.
					$core = implode(
						', ',
						array_map(
							array( $db, 'quote' ),
							array_map(
								static function ( string $key ) use ( $prefix ): string {
									return $prefix . $key;
								},
								self::SITE_USER_KEYS
							)
						)
					);
					$db->query( "DELETE FROM {$meta} WHERE `meta_key` IN ({$core})" );
					$db->query( "DELETE `m` FROM {$meta} `m` JOIN {$meta} `s` ON `s`.`user_id` = `m`.`user_id` AND `s`.`meta_key` = CONCAT(" . $db->quote( $own ) . ', SUBSTRING(`m`.`meta_key`, ' . ( strlen( $prefix ) + 1 ) . ')) WHERE `m`.`meta_key` LIKE ' . $like( $prefix ) );
					$db->query( "UPDATE {$meta} SET `meta_key` = CONCAT(" . $db->quote( $prefix ) . ', SUBSTRING(`meta_key`, ' . ( strlen( $own ) + 1 ) . ')) WHERE `meta_key` LIKE ' . $like( $own ) );
				}
				foreach ( $super as $id ) {
					$db->query( "DELETE FROM {$meta} WHERE `user_id` = {$id} AND `meta_key` IN (" . $db->quote( $prefix . 'capabilities' ) . ', ' . $db->quote( $prefix . 'user_level' ) . ')' );
					$db->query( "INSERT INTO {$meta} (`user_id`, `meta_key`, `meta_value`) VALUES ({$id}, " . $db->quote( $prefix . 'capabilities' ) . ', ' . $db->quote( serialize( array( 'administrator' => true ) ) ) . "), ({$id}, " . $db->quote( $prefix . 'user_level' ) . ", '10')" );
				}
			}

			if ( $has( 'options' ) ) {
				$options = $t( 'options' );
				if ( 1 !== $blog ) {
					$db->query( "UPDATE {$options} SET `option_name` = " . $db->quote( $prefix . 'user_roles' ) . ' WHERE `option_name` = ' . $db->quote( $own . 'user_roles' ) );
				}
				if ( $sitewide ) {
					$raw     = $db->column( "SELECT `option_value` FROM {$options} WHERE `option_name` = 'active_plugins'" );
					$plugins = isset( $raw[0] ) ? unserialize( (string) $raw[0], array( 'allowed_classes' => false ) ) : array();
					$plugins = array_values( array_unique( array_merge( is_array( $plugins ) ? array_filter( $plugins, 'is_string' ) : array(), $sitewide ) ) );
					sort( $plugins );
					$value = $db->quote( serialize( $plugins ) );
					$db->query( isset( $raw[0] ) ? "UPDATE {$options} SET `option_value` = {$value} WHERE `option_name` = 'active_plugins'" : "INSERT INTO {$options} (`option_name`, `option_value`) VALUES ('active_plugins', {$value})" );
				}
			}
			$restore->set_progress( 'subsite', 'done' );
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			throw $e;
		}
		$context->log( sprintf( 'Made site %d a single site: %d users without a role, posts or comments on it left out, %d super admins as administrators, %d network-activated plugins now active.', $blog, $removed, count( $super ), count( $sitewide ) ) );
	}

	/**
	 * URLs and paths to replace, URLs to keep, and e-mail domains to keep, for the chosen site.
	 *
	 * @param array<string,mixed> $plan   From resolve().
	 * @param array<string,mixed> $site   Manifest site.
	 * @param array<string,mixed> $target Restore target.
	 * @return array{urls:array<string,string>,paths:array<string,string>,keep:string[],keep_email:string[]}
	 */
	public static function pairs( array $plan, array $site, array $target ): array {
		$blog    = (int) $plan['blog_id'];
		$main_id = (int) ( $plan['main_site'] ?? 1 );
		$url     = 'http://' . $plan['domain'] . rtrim( (string) $plan['path'], '/' );
		$main    = 'http://' . (string) ( $plan['network_domain'] ?? $plan['domain'] ) . rtrim( (string) ( $plan['network_path'] ?? '/' ), '/' );
		$uploads = trim( (string) ( $site['uploads_dir'] ?? 'wp-content/uploads' ), '/' );
		$content = trim( (string) ( $site['content_dir'] ?? 'wp-content' ), '/' );
		$home    = (string) ( $target['home_url'] ?? '' );
		$to_up   = (string) ( $target['uploads_url'] ?? rtrim( $home, '/' ) . '/wp-content/uploads' );
		$urls    = array( $url => $home ); // First: the home URL decides where e-mail domains go.
		if ( $blog !== $main_id ) {
			// A site's media URLs appear under its own address and under the network's; old networks used /files/.
			$urls[ $url . '/' . $uploads . '/sites/' . $blog ]  = $to_up;
			$urls[ $main . '/' . $uploads . '/sites/' . $blog ] = $to_up;
			$urls[ $url . '/files' ]                            = $to_up;
			$urls[ $main . '/' . $content . '/blogs.dir/' . $blog . '/files' ] = $to_up;
		}

		$paths  = array();
		$source = rtrim( (string) ( $site['abspath'] ?? '' ), '/' );
		if ( '' !== $source && ! empty( $target['uploads_dir'] ) && $blog !== $main_id ) {
			$paths[ $source . '/' . $uploads . '/sites/' . $blog ]                = (string) $target['uploads_dir'];
			$paths[ $source . '/' . $content . '/blogs.dir/' . $blog . '/files' ] = (string) $target['uploads_dir'];
		}
		if ( '' !== $source ) {
			$paths[ $source ] = rtrim( (string) ( $target['abspath'] ?? '' ), '/' );
		}

		$keep = array();
		foreach ( (array) ( $site['sites'] ?? array() ) as $other ) {
			$id = (int) ( is_array( $other ) ? ( $other['blog_id'] ?? 0 ) : 0 );
			if ( $id > 0 && $id !== $blog ) {
				$keep[] = '//' . strtolower( (string) ( $other['domain'] ?? '' ) ) . (string) ( $other['path'] ?? '/' );
				$keep[] = '//' . substr( $main, 7 ) . '/' . $uploads . '/sites/' . $id; // Its media under the network's address.
			}
		}
		// The network's own e-mail domain stays with the network unless its main site is the one leaving.
		$keep_email = $blog === $main_id ? array() : array( strtolower( (string) ( $plan['network_domain'] ?? '' ) ) );
		return array(
			'urls'       => $urls,
			'paths'      => $paths,
			'keep'       => $keep,
			'keep_email' => array_map(
				static function ( string $domain ): string {
					return (string) preg_replace( '/:\d+$/', '', $domain );
				},
				array_filter( $keep_email )
			),
		);
	}
}
