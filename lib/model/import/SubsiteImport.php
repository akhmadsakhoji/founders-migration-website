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
 * Restores a single-site backup as a site of this multisite network.
 *
 * The site is either new (an address that no site has yet: it gets the next
 * free site ID) or an existing site whose content it replaces (not the main
 * site). For site ID N:
 *
 * - tables: wp_posts becomes wp_N_posts and so on; users and usermeta are
 *   imported beside them and merged into the network's users just before
 *   the switch: a user whose login exists here is that user (password and
 *   profile untouched), others are added; everyone gets their role on site
 *   N, and authors of posts, comments and links point at the merged IDs;
 * - files: uploads/ becomes uploads/sites/N/; themes and plugins join the
 *   network's; mu-plugins and files directly in wp-content (drop-ins) are
 *   left out, they would change every site;
 * - URLs: the backup's address and uploads URL become the site's.
 */
final class SubsiteImport {

	/**
	 * Mapping of backup user IDs to this network's, kept until the restore ends (not a site table).
	 */
	const USERMAP = 'fmwtmp__usermap';

	/**
	 * Per-site user meta keys WordPress writes with the site's prefix.
	 */
	const SITE_USER_KEYS = array( 'capabilities', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id', 'persisted_preferences' );

	/**
	 * Backup users merged per transaction.
	 */
	const USER_BATCH = 200;

	/**
	 * Rows of posts, comments or links whose user IDs are remapped per transaction.
	 */
	const REMAP_BATCH = 5000;

	/**
	 * Folders WordPress reserves on subdirectory networks.
	 */
	const RESERVED = array( 'page', 'comments', 'blog', 'files', 'feed', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'embed' );

	/**
	 * Works out the site a single-site backup becomes.
	 *
	 * @param array<string,mixed> $target Restore target (multisite, network, sites).
	 * @param string              $choice Existing site ID or address, or a new address (shop, shop.example.com, example.com/shop, a URL).
	 * @return array{blog_id:int,domain:string,path:string,new:bool}
	 * @throws JobException When the choice is not usable.
	 */
	public static function resolve( array $target, string $choice ): array {
		$network = (array) ( $target['network'] ?? array() );
		$domain  = strtolower( (string) ( $network['domain'] ?? '' ) );
		$base    = 0 === strpos( $domain, 'www.' ) ? substr( $domain, 4 ) : $domain;
		$npath   = self::slashed( (string) ( $network['path'] ?? '/' ) );
		$choice  = trim( strtolower( $choice ), " \t\n\r\0\x0B" );
		$address = rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $choice ), '/' );
		if ( (int) ( $network['networks'] ?? 1 ) > 1 ) {
			throw new JobException( 'This install has several networks; restoring a single site into one of them is not supported.' );
		}

		$existing = self::existing( $target, ctype_digit( $choice ) ? (int) $choice : null, $address );
		if ( null !== $existing ) {
			return $existing;
		}
		if ( ctype_digit( $choice ) ) {
			throw new JobException( sprintf( 'This network has no site %d.', (int) $choice ) );
		}

		// A new site: a slug, or a full address.
		$slug = 1 === preg_match( '/^[a-z0-9][a-z0-9-]*$/D', $address );
		if ( $slug ) {
			$new = empty( $network['subdomain'] )
				? array( $domain, $npath . $address . '/' )
				: array( $address . '.' . $base, $npath );
		} else {
			$slash = strpos( $address, '/' );
			$new   = array( false === $slash ? $address : substr( $address, 0, $slash ), self::slashed( false === $slash ? '/' : substr( $address, $slash ) ) );
		}
		if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?$/D', $new[0] ) || 1 !== preg_match( '#^/([a-z0-9][a-z0-9_-]*/)*$#D', $new[1] ) ) {
			throw new JobException( sprintf( '"%s" is not a site address: give a name (shop), a domain (shop.example.com) or an address in this network (example.com/shop).', SubsiteExtract::printable( $choice ) ) );
		}
		if ( $new[0] === $domain && $new[1] !== $npath ) {
			if ( ! empty( $network['subdomain'] ) ) {
				throw new JobException( 'This network uses subdomains: give the new site a domain (shop or shop.' . $base . '), not a folder.' );
			}
			$folder = trim( substr( $new[1], strlen( $npath ) ), '/' );
			if ( false !== strpos( $folder, '/' ) || in_array( $folder, self::RESERVED, true ) ) {
				throw new JobException( sprintf( '"%s" cannot be a site folder in this network: use one name that WordPress does not reserve.', SubsiteExtract::printable( $folder ) ) );
			}
		}
		// A name that turns out to be a site's address (shop → net.example/shop/): replacing it takes its ID or full address.
		$existing = self::existing( $target, null, rtrim( $new[0] . $new[1], '/' ) );
		if ( null !== $existing && $slug ) {
			throw new JobException( sprintf( '"%1$s" is already site %2$d (%3$s): to replace it give its ID (%2$d) or address (%3$s); for a new site choose another name.', SubsiteExtract::printable( $choice ), $existing['blog_id'], SubsiteExtract::printable( $existing['domain'] . $existing['path'] ) ) );
		}
		if ( null !== $existing ) {
			return $existing;
		}
		return array(
			'blog_id' => 0,
			'domain'  => $new[0],
			'path'    => $new[1],
			'new'     => true,
		);
	}

	/**
	 * The network's site with this ID or address (domain + path, no trailing slash), refusing the ones that cannot be replaced.
	 *
	 * @param array<string,mixed> $target  Restore target (network, sites).
	 * @param int|null            $id      Site ID, or null.
	 * @param string              $address Lower-case address.
	 * @return array{blog_id:int,domain:string,path:string,new:bool}|null
	 * @throws JobException For the main site or site 1.
	 */
	private static function existing( array $target, ?int $id, string $address ): ?array {
		$main = (int) ( $target['network']['main_site'] ?? 1 );
		foreach ( (array) ( $target['sites'] ?? array() ) as $site ) {
			$blog  = (int) ( $site['blog_id'] ?? 0 );
			$where = strtolower( (string) ( $site['domain'] ?? '' ) ) . self::slashed( (string) ( $site['path'] ?? '/' ) );
			if ( $id !== $blog && rtrim( $where, '/' ) !== $address ) {
				continue;
			}
			if ( $blog === $main ) {
				throw new JobException( 'That is the network\'s main site; restore a single site into another site of the network.' );
			}
			if ( 1 === $blog ) {
				throw new JobException( 'Site 1 keeps the network\'s first tables and media folder; restore a single site into another site of the network.' );
			}
			return array(
				'blog_id' => $blog,
				'domain'  => strtolower( (string) $site['domain'] ),
				'path'    => self::slashed( (string) $site['path'] ),
				'new'     => false,
			);
		}
		return null;
	}

	/**
	 * Plans the restore of a single site into this network and points the job's target at the site.
	 *
	 * The network's own target stays in job data (network_target); job
	 * options target then carries the site's address, uploads URL and
	 * uploads folder, so the replace plans work as for a single site.
	 *
	 * @param \Founders\Migration\Job\Job $job     Job (options target of the network, subsite choice).
	 * @param Context                     $context Context.
	 * @return array{blog_id:int,domain:string,path:string,new:bool}
	 * @throws JobException When no site is chosen or the choice is not usable.
	 */
	public static function plan_for( \Founders\Migration\Job\Job $job, Context $context ): array {
		$target = is_array( $job->data['network_target'] ?? null ) ? $job->data['network_target'] : (array) ( $job->options['target'] ?? array() );
		$choice = (string) ( $job->options['subsite'] ?? '' );
		if ( '' === $choice ) {
			throw new JobException( 'This is a backup of a single site and this is a multisite network: choose the site it becomes with --site=<new address or existing site> (for example --site=shop), or in the Restore dialog.' );
		}
		$plan = self::resolve( $target, $choice );
		if ( $plan['new'] ) {
			$restore         = new RestoreDatabase();
			$plan['blog_id'] = self::next_id( $restore->db(), (string) ( $target['table_prefix'] ?? 'wp_' ) );
			$restore->db()->close();
		}
		$address                     = self::address( $plan, $target );
		$job->data['network_target'] = $target;
		$job->options['target']      = array(
			'home_url'    => $address['home_url'],
			'site_url'    => $address['home_url'],
			'uploads_url' => $address['uploads_url'],
			'uploads_dir' => $address['uploads_dir'],
		) + $target;
		$context->log(
			sprintf(
				$plan['new'] ? 'The backup becomes a new site of the network: site %d at %s.' : 'The backup replaces site %d of the network, at %s.',
				$plan['blog_id'],
				SubsiteExtract::printable( $plan['domain'] . $plan['path'] )
			)
		);
		return $plan;
	}

	/**
	 * The next free site ID: after the highest site and after any leftover wp_<id>_ tables.
	 *
	 * @param Connection $db     Connection.
	 * @param string     $prefix This network's prefix.
	 * @return int
	 */
	public static function next_id( Connection $db, string $prefix ): int {
		$max  = $db->column( 'SELECT COALESCE(MAX(`blog_id`), 1) FROM ' . Connection::identifier( $prefix . 'blogs' ) );
		$id   = (int) ( $max[0] ?? 1 ) + 1;
		$like = $db->escape( addcslashes( $prefix, '\\%_' ) ) . '%';
		foreach ( $db->column( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$like}'" ) as $table ) {
			if ( 1 === preg_match( '/^([1-9][0-9]*)_/', substr( (string) $table, strlen( $prefix ) ), $m ) && (int) $m[1] >= $id ) {
				$id = (int) $m[1] + 1;
			}
		}
		// Nor an ID of a deleted site: WordPress would not give it out again either.
		$status = $db->rows( 'SHOW TABLE STATUS LIKE ' . $db->quote( addcslashes( $prefix . 'blogs', '\\%_' ) ) );
		return max( $id, (int) ( $status[0]['Auto_increment'] ?? 0 ) );
	}

	/**
	 * Keeps a new site's ID for this restore: sites made meanwhile get the IDs after it.
	 *
	 * @param array<string,mixed> $plan   From plan_for().
	 * @param array<string,mixed> $target Restore target of the network (table_prefix).
	 * @return void
	 */
	public static function reserve( array $plan, array $target ): void {
		$last = 0;
		foreach ( self::sites( $plan ) as $site ) {
			if ( ! empty( $site['new'] ) ) {
				$last = max( $last, (int) $site['blog_id'] );
			}
		}
		if ( 0 === $last ) {
			return;
		}
		$restore = new RestoreDatabase();
		$restore->db()->query( 'ALTER TABLE ' . Connection::identifier( (string) ( $target['table_prefix'] ?? 'wp_' ) . 'blogs' ) . ' AUTO_INCREMENT = ' . ( $last + 1 ) );
		$restore->db()->close();
	}

	/**
	 * The sites of an import plan: the list of a backup of picked sites, or the one site of a single-site backup (from 0).
	 *
	 * @param array<string,mixed> $plan Import plan.
	 * @return array<int,array{from:int,blog_id:int,domain:string,path:string,new:bool}>
	 */
	public static function sites( array $plan ): array {
		if ( isset( $plan['sites'] ) && is_array( $plan['sites'] ) && $plan['sites'] ) {
			return array_values( $plan['sites'] );
		}
		return array(
			array(
				'from'    => 0,
				'blog_id' => (int) ( $plan['blog_id'] ?? 0 ),
				'domain'  => (string) ( $plan['domain'] ?? '' ),
				'path'    => (string) ( $plan['path'] ?? '/' ),
				'new'     => ! empty( $plan['new'] ),
			),
		);
	}

	/**
	 * Name (after the prefix) a table of the backup gets: N_posts, or users / usermeta to be merged.
	 *
	 * @param string $table  Source table.
	 * @param string $prefix Source prefix.
	 * @param int    $blog   Site ID.
	 * @return string|null
	 */
	public static function table( string $table, string $prefix, int $blog ): ?string {
		if ( 0 !== strpos( $table, $prefix ) ) {
			return null;
		}
		$rest = substr( $table, strlen( $prefix ) );
		return in_array( $rest, array( 'users', 'usermeta' ), true ) ? $rest : $blog . '_' . $rest;
	}

	/**
	 * Where a file of the backup goes (relative to wp-content), or null when it stays out.
	 *
	 * @param string $relative Path in wp-content.
	 * @param string $uploads  Uploads folder relative to wp-content ("uploads").
	 * @param int    $blog     Site ID.
	 * @return string|null
	 */
	public static function file( string $relative, string $uploads, int $blog ): ?string {
		$uploads = trim( $uploads, '/' );
		if ( '' !== $uploads && ( $relative === $uploads || 0 === strpos( $relative, $uploads . '/' ) ) ) {
			return $uploads . '/sites/' . $blog . substr( $relative, strlen( $uploads ) );
		}
		$top = explode( '/', $relative, 2 )[0];
		return in_array( $top, array( 'plugins', 'themes', 'languages' ), true ) && $top !== $relative ? $relative : null;
	}

	/**
	 * The sites of a backup of picked sites and the site of this network each becomes, from --site:
	 * "shop" when the backup holds one site, else "2=shop,3=4" (old ID or address = new address or existing site).
	 * Sites left out of the list stay in the backup.
	 *
	 * @param array<string,mixed> $source The backup's network (WpressNetwork::site()).
	 * @param array<string,mixed> $target Restore target of this network (network, sites).
	 * @param string              $choice The --site value.
	 * @return array<int,array{from:int,blog_id:int,domain:string,path:string,new:bool}> New sites have blog_id 0.
	 * @throws JobException When the choice is missing, unclear or not usable.
	 */
	public static function picked_choices( array $source, array $target, string $choice ): array {
		$ids    = array_map( 'intval', array_column( (array) ( $source['sites'] ?? array() ), 'blog_id' ) );
		$choice = trim( $choice, " \t\n\r\0\x0B" );
		$how    = sprintf( '--site=<site of the backup>=<new address or existing site>[,...], for example --site=%d=shop (a new site) or --site=%d=3 (replaces site 3). Its sites: %s.', $ids[0] ?? 2, $ids[0] ?? 2, SubsiteExtract::listing( $source ) );
		if ( '' === $choice ) {
			throw new JobException( sprintf( 'This backup holds %d site(s) picked from a network and this is a multisite network: choose the site each becomes with %s', count( $ids ), $how ) );
		}
		$pairs = array();
		if ( false === strpos( $choice, '=' ) ) {
			if ( 1 !== count( $ids ) ) {
				throw new JobException( sprintf( 'This backup holds %d sites: give the site each becomes with %s', count( $ids ), $how ) );
			}
			$pairs[] = array( (string) $ids[0], $choice );
		} else {
			foreach ( explode( ',', $choice ) as $item ) {
				$item = trim( $item, " \t\n\r\0\x0B" );
				$eq   = strpos( $item, '=' );
				if ( '' === $item ) {
					continue;
				}
				if ( false === $eq ) {
					throw new JobException( sprintf( '"%s" is not <site of the backup>=<new address or existing site>; use %s', SubsiteExtract::printable( $item ), $how ) );
				}
				$pairs[] = array( substr( $item, 0, $eq ), substr( $item, $eq + 1 ) );
			}
		}
		$sites = array();
		$from  = array();
		$into  = array();
		foreach ( $pairs as $pair ) {
			$old = (int) SubsiteExtract::resolve( $source, $pair[0] )['blog_id'];
			if ( isset( $from[ $old ] ) ) {
				throw new JobException( sprintf( 'Site %d of the backup is given twice.', $old ) );
			}
			$plan = self::resolve( $target, $pair[1] );
			$key  = $plan['new'] ? $plan['domain'] . $plan['path'] : (string) $plan['blog_id'];
			if ( isset( $into[ $key ] ) ) {
				throw new JobException( sprintf( 'Sites %d and %d of the backup would both become %s.', $into[ $key ], $old, SubsiteExtract::printable( $plan['domain'] . $plan['path'] ) ) );
			}
			$from[ $old ] = true;
			$into[ $key ] = $old;
			$sites[]      = array( 'from' => $old ) + $plan;
		}
		if ( ! $sites ) {
			throw new JobException( 'No site of the backup is chosen; use ' . $how );
		}
		return $sites;
	}

	/**
	 * Plans the restore of a backup of picked sites into this network: each chosen site becomes a new site or replaces one.
	 *
	 * Job options target stays the network's; the network's target is also kept in job data (network_target).
	 *
	 * @param \Founders\Migration\Job\Job $job     Job (options target of the network, subsite choice).
	 * @param array<string,mixed>         $source  The backup's network (WpressNetwork::site()).
	 * @param Context                     $context Context.
	 * @return array<string,mixed> Import plan with sites.
	 * @throws JobException When the choice is not usable.
	 */
	public static function plan_picked( \Founders\Migration\Job\Job $job, array $source, Context $context ): array {
		$target = is_array( $job->data['network_target'] ?? null ) ? $job->data['network_target'] : (array) ( $job->options['target'] ?? array() );
		$sites  = self::picked_choices( $source, $target, (string) ( $job->options['subsite'] ?? '' ) );
		if ( array_filter( array_column( $sites, 'new' ) ) ) {
			$restore = new RestoreDatabase();
			$next    = self::next_id( $restore->db(), (string) ( $target['table_prefix'] ?? 'wp_' ) );
			$restore->db()->close();
			foreach ( $sites as $i => $site ) {
				if ( $site['new'] ) {
					$sites[ $i ]['blog_id'] = $next++;
				}
			}
		}
		$job->data['network_target'] = $target;
		foreach ( $sites as $site ) {
			$context->log(
				sprintf(
					$site['new'] ? 'Site %d of the backup becomes a new site of the network: site %d at %s.' : 'Site %d of the backup replaces site %d of the network, at %s.',
					$site['from'],
					$site['blog_id'],
					SubsiteExtract::printable( $site['domain'] . $site['path'] )
				)
			);
		}
		$network = (array) ( $source['network'] ?? array() );
		return array(
			'blog_id'        => $sites[0]['blog_id'],
			'domain'         => $sites[0]['domain'],
			'path'           => $sites[0]['path'],
			'new'            => $sites[0]['new'],
			'sites'          => $sites,
			'picked'         => true,
			'uploads'        => 'uploads',
			'source_ids'     => array_values( array_unique( array_merge( array( 1 ), array_map( 'intval', array_column( (array) ( $source['sites'] ?? array() ), 'blog_id' ) ) ) ) ),
			'network_domain' => strtolower( (string) ( $network['domain'] ?? '' ) ),
			'network_path'   => (string) ( $network['path'] ?? '/' ),
		);
	}

	/**
	 * Name (after the prefix) a table of a backup of picked sites gets: <new id>_posts, or users / usermeta to be merged,
	 * or blogs (the backup's network, read for its addresses, then dropped); null when it stays in the backup.
	 *
	 * @param string                                 $table Source table.
	 * @param array<int,array{from:int,blog_id:int}> $sites Chosen sites.
	 * @return string|null
	 */
	public static function picked_table( string $table, array $sites ): ?string {
		$mask = \Founders\Migration\Archive\WpressPackage::SQL_PREFIX;
		if ( 0 !== strpos( $table, $mask ) ) {
			return null;
		}
		$map  = array_column( $sites, 'blog_id', 'from' );
		$rest = substr( $table, strlen( $mask ) );
		if ( 0 === strpos( $rest, 'mainsite_' ) ) {
			$rest = substr( $rest, 9 );
			return in_array( $rest, array( 'users', 'usermeta', 'blogs' ), true ) ? $rest : null;
		}
		if ( 0 === strpos( $rest, 'basesite_' ) ) {
			return isset( $map[1] ) && strlen( $rest ) > 9 ? (int) $map[1] . '_' . substr( $rest, 9 ) : null;
		}
		if ( 1 === preg_match( '/^([1-9][0-9]*)_(.+)$/', $rest, $m ) && isset( $map[ (int) $m[1] ] ) ) {
			return (int) $map[ (int) $m[1] ] . '_' . $m[2];
		}
		return null;
	}

	/**
	 * Where a file of a backup of picked sites goes (relative to wp-content), or null when it stays out.
	 *
	 * Media in uploads/sites/<old id>/ (blogs.dir/<old id>/files/ on old networks) moves
	 * to uploads/sites/<new id>/; the main site's media in uploads/ itself goes there
	 * too when the main site is chosen; themes, plugins and languages join the network's.
	 *
	 * @param string                                 $relative Path in wp-content.
	 * @param string                                 $uploads  Uploads folder relative to wp-content ("uploads").
	 * @param array<int,array{from:int,blog_id:int}> $sites    Chosen sites.
	 * @return string|null
	 */
	public static function picked_file( string $relative, string $uploads, array $sites ): ?string {
		$map     = array_column( $sites, 'blog_id', 'from' );
		$uploads = trim( $uploads, '/' );
		if ( 1 === preg_match( '#^blogs\.dir/([1-9][0-9]*)/files(/.*)?$#D', $relative, $m ) ) {
			return isset( $map[ (int) $m[1] ] ) && '' !== $uploads ? $uploads . '/sites/' . (int) $map[ (int) $m[1] ] . ( $m[2] ?? '' ) : null;
		}
		if ( '' !== $uploads && ( $relative === $uploads || 0 === strpos( $relative, $uploads . '/' ) ) ) {
			$rest = substr( $relative, strlen( $uploads ) );
			if ( 1 === preg_match( '#^/sites/([1-9][0-9]*)(/.*)?$#D', $rest, $m ) ) {
				return isset( $map[ (int) $m[1] ] ) ? $uploads . '/sites/' . (int) $map[ (int) $m[1] ] . ( $m[2] ?? '' ) : null;
			}
			if ( '/sites' === $rest || '' === $rest ) {
				return null;
			}
			return isset( $map[1] ) ? $uploads . '/sites/' . (int) $map[1] . $rest : null; // The main site's media.
		}
		$top = explode( '/', $relative, 2 )[0];
		return in_array( $top, array( 'plugins', 'themes', 'languages' ), true ) && $top !== $relative ? $relative : null;
	}

	/**
	 * URL, path and value pairs for the replace step of a backup of picked sites: each chosen site's
	 * address, uploads URL and uploads folder become its new ones.
	 *
	 * @param array<int,array<string,mixed>> $entries  multisite.json Sites[] by old ID (WpressNetwork::entries()).
	 * @param array<string,mixed>            $plan     Import plan.
	 * @param array<string,mixed>            $target   Restore target of the network.
	 * @param array<string,mixed>            $package  package.json WordPress Content and Absolute, and its find/replace pairs under raw.
	 * @param bool                           $email    Replace e-mail domains.
	 * @return array{urls:array<string,string>,paths:array<string,string>,raw:array<string,string>,email:bool}
	 */
	public static function picked_replace_plan( array $entries, array $plan, array $target, array $package, bool $email ): array {
		$urls  = array();
		$paths = array();
		$sites = self::sites( $plan );
		usort(
			$sites,
			static function ( array $a, array $b ): int {
				return ( 1 === (int) $a['from'] ? 0 : 1 ) - ( 1 === (int) $b['from'] ? 0 : 1 );
			}
		);
		// Addresses first, the main site's before the others: the first URL of a host decides where its e-mail domain goes.
		foreach ( $sites as $site ) {
			$entry   = (array) ( $entries[ (int) $site['from'] ] ?? array() );
			$address = self::address( $site, $target );
			foreach ( array( 'HomeURL', 'SiteURL' ) as $key ) {
				if ( is_string( $entry[ $key ] ?? null ) && '' !== $entry[ $key ] ) {
					$urls += array( rtrim( $entry[ $key ], '/' ) => $address['home_url'] );
				}
			}
		}
		foreach ( $sites as $site ) {
			$entry   = (array) ( $entries[ (int) $site['from'] ] ?? array() );
			$address = self::address( $site, $target );
			$home    = rtrim( (string) ( $entry['HomeURL'] ?? '' ), '/' );
			if ( is_string( $entry['WordPress']['UploadsURL'] ?? null ) && '' !== $entry['WordPress']['UploadsURL'] ) {
				$urls[ rtrim( $entry['WordPress']['UploadsURL'], '/' ) ] = $address['uploads_url'];
			}
			if ( '' !== $home ) {
				// The site's media under its own address too; old networks used /files/.
				$urls[ $home . '/wp-content/uploads' . ( (int) $site['from'] > 1 ? '/sites/' . (int) $site['from'] : '' ) ] = $address['uploads_url'];
				if ( (int) $site['from'] > 1 ) {
					$urls[ $home . '/files' ] = $address['uploads_url'];
				}
			}
			if ( is_string( $entry['WordPress']['Uploads'] ?? null ) && '' !== $entry['WordPress']['Uploads'] ) {
				$paths[ rtrim( $entry['WordPress']['Uploads'], '/' ) ] = $address['uploads_dir'];
			}
		}
		foreach ( array(
			'Content'  => 'content_dir',
			'Absolute' => 'abspath',
		) as $key => $name ) {
			if ( '' !== (string) ( $package[ $key ] ?? '' ) && ! empty( $target[ $name ] ) ) {
				$paths += array( rtrim( (string) $package[ $key ], '/' ) => rtrim( (string) $target[ $name ], '/' ) );
			}
		}
		return array(
			'urls'  => $urls,
			'paths' => $paths,
			'raw'   => (array) ( $package['raw'] ?? array() ),
			'email' => $email,
		);
	}

	/**
	 * Addresses the replace step keeps for a backup of picked sites: the backup network's other sites
	 * (picked or not) and their media under the network's address; the network's e-mail domain unless its main site moves.
	 *
	 * @param array<string,mixed> $plan Import plan (network_sites noted by WpressDatabaseStep).
	 * @return array{keep:string[],keep_email:string[]}
	 */
	public static function picked_keep( array $plan ): array {
		$moved = array_map( 'intval', array_column( self::sites( $plan ), 'from' ) );
		$main  = (string) ( $plan['network_domain'] ?? '' ) . rtrim( (string) ( $plan['network_path'] ?? '/' ), '/' );
		$keep  = array();
		foreach ( (array) ( $plan['network_sites'] ?? array() ) as $other ) {
			$id = (int) ( $other['blog_id'] ?? 0 );
			if ( $id > 0 && ! in_array( $id, $moved, true ) ) {
				$keep[] = '//' . strtolower( (string) ( $other['domain'] ?? '' ) ) . (string) ( $other['path'] ?? '/' );
				if ( '' !== $main && 1 !== $id ) {
					$keep[] = '//' . $main . '/wp-content/uploads/sites/' . $id;
				}
			}
		}
		return array(
			'keep'       => $keep,
			'keep_email' => in_array( 1, $moved, true ) || '' === (string) ( $plan['network_domain'] ?? '' ) ? array() : array( (string) preg_replace( '/:\d+$/', '', (string) $plan['network_domain'] ) ),
		);
	}

	/**
	 * Prepares the imported site tables (before the replace): the roles option gets the site's prefix. Idempotent.
	 *
	 * @param RestoreDatabase $restore Database helper.
	 * @param int             $blog    Site ID.
	 * @param string          $prefix  Source prefix.
	 * @param int             $from    The site's ID in the backup's network (0 or 1: bare prefix).
	 * @return void
	 */
	public static function prepare( RestoreDatabase $restore, int $blog, string $prefix, int $from = 0 ): void {
		$db      = $restore->db();
		$options = RestoreDatabase::TMP . $blog . '_options';
		if ( in_array( $options, $restore->imported_tables(), true ) ) {
			$db->query( 'UPDATE ' . Connection::identifier( $options ) . ' SET `option_name` = ' . $db->quote( $prefix . $blog . '_user_roles' ) . ' WHERE `option_name` = ' . $db->quote( $prefix . ( $from > 1 ? $from . '_' : '' ) . 'user_roles' ) );
		}
	}

	/**
	 * Refuses to go on when a new site's ID or address was taken while the restore ran. Runs before anything live changes.
	 *
	 * @param RestoreDatabase     $restore Database helper.
	 * @param array<string,mixed> $plan    From resolve(), with blog_id set.
	 * @param array<string,mixed> $target  Restore target of the network (table_prefix).
	 * @return void
	 * @throws JobException When the ID or address is taken.
	 */
	public static function check_site( RestoreDatabase $restore, array $plan, array $target ): void {
		if ( 'done' === $restore->progress( 'site-row' ) ) {
			return;
		}
		$db    = $restore->db();
		$blogs = Connection::identifier( (string) ( $target['table_prefix'] ?? 'wp_' ) . 'blogs' );
		foreach ( self::sites( $plan ) as $site ) {
			if ( empty( $site['new'] ) ) {
				continue;
			}
			$blog = (int) $site['blog_id'];
			if ( $db->column( "SELECT `blog_id` FROM {$blogs} WHERE `blog_id` = {$blog}" ) ) {
				throw new JobException( sprintf( 'Site ID %d was taken by another site while the restore ran; start the restore again.', $blog ) );
			}
			$taken = $db->column( "SELECT `blog_id` FROM {$blogs} WHERE `domain` = " . $db->quote( (string) $site['domain'] ) . ' AND `path` = ' . $db->quote( (string) $site['path'] ) );
			if ( isset( $taken[0] ) ) {
				throw new JobException( sprintf( 'Another site (%d) now has the address %s%s; start the restore again.', (int) $taken[0], SubsiteExtract::printable( (string) $site['domain'] ), SubsiteExtract::printable( (string) $site['path'] ) ) );
			}
		}
	}

	/**
	 * Merges the backup's users into this network's and points the site's content at them, a batch at a time. Runs just before the switch.
	 *
	 * Users are matched by login, then by e-mail address; each batch of users
	 * is committed with its place in the user map, and each batch of posts,
	 * comments and links with its place in the table, so an interrupted merge
	 * goes on where it stopped and no author is mapped twice.
	 *
	 * @param RestoreDatabase     $restore Database helper.
	 * @param array<string,mixed> $plan    From resolve(), with blog_id set.
	 * @param array<string,mixed> $target  Restore target of the network (table_prefix).
	 * @param Context             $context Context.
	 * @return bool Whether the merge is finished.
	 * @throws \Throwable After rolling back, when a query fails.
	 */
	public static function merge_users( RestoreDatabase $restore, array $plan, array $target, Context $context ): bool {
		$db     = $restore->db();
		$sites  = self::sites( $plan );
		$blog   = (int) $sites[0]['blog_id']; // New users' primary site.
		$to     = (string) ( $target['table_prefix'] ?? 'wp_' );
		$tables = array_flip( $restore->imported_tables() );
		$tmp    = static function ( string $name ): string {
			return Connection::identifier( RestoreDatabase::TMP . $name );
		};
		$live   = static function ( string $name ) use ( $to ): string {
			return Connection::identifier( $to . $name );
		};
		$map    = Connection::identifier( self::USERMAP );

		$db->query( "CREATE TABLE IF NOT EXISTS {$map} (`old_id` bigint unsigned NOT NULL PRIMARY KEY, `new_id` bigint unsigned NOT NULL) ENGINE=InnoDB" );
		$users_done = in_array( $restore->progress( 'site-users' ), array( 'done', 'none' ), true );
		if ( ! $users_done ) {
			foreach ( $sites as $site ) {
				foreach ( array( 'posts', 'comments', 'links' ) as $name ) {
					if ( isset( $tables[ RestoreDatabase::TMP . (int) $site['blog_id'] . '_' . $name ] ) ) {
						self::innodb( $db, RestoreDatabase::TMP . (int) $site['blog_id'] . '_' . $name, $context );
					}
				}
			}
		}
		if ( ! $users_done && ! isset( $tables[ RestoreDatabase::TMP . 'users' ] ) ) {
			$restore->set_progress( 'site-users', 'none' );
			$context->log( 'The backup has no users table: its posts, comments and links are left without an author on this network.' );
		} elseif ( ! $users_done ) {
			$columns = array();
			foreach ( $db->rows( 'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( $to . 'users' ) ) as $column ) {
				$columns[] = (string) $column['name'];
			}
			$copy = array_values( array_intersect( array( 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name' ), $columns ) );
			// Keys of any site of the backup's network (wp_3_capabilities) would give a role on this network's site 3: never copied as they are.
			$foreign = '';
			foreach ( array_unique( array_map( 'intval', (array) ( $plan['source_ids'] ?? array() ) ) ) as $id ) {
				$foreign .= ' AND `meta_key` NOT LIKE ' . $db->quote( addcslashes( $to . $id . '_', '\\%_' ) . '%' );
			}
			$state = json_decode( (string) $restore->progress( 'site-users' ), true );
			$state = ( is_array( $state ) ? $state : array() ) + array( 'after' => 0, 'found' => 0, 'added' => 0, 'left' => 0 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short state.

			do {
				if ( ! $context->should_continue() ) {
					return false;
				}
				$users = $db->rows( 'SELECT * FROM ' . $tmp( 'users' ) . ' WHERE `ID` > ' . (int) $state['after'] . ' ORDER BY `ID` LIMIT ' . self::USER_BATCH );
				$roles = $users && ! empty( $plan['picked'] ) ? self::chosen_roles( $db, $sites, $to, array_map( 'intval', array_column( $users, 'ID' ) ), $tables ) : array();
				$db->query( 'START TRANSACTION' );
				try {
					foreach ( $users as $user ) {
						$state['after'] = (int) $user['ID'];
						if ( ! empty( $plan['picked'] ) && ! isset( $roles[ (int) $user['ID'] ] ) ) {
							++$state['left']; // Only on sites left in the backup: not a user of this network.
							continue;
						}
						$primary  = $sites[ $roles[ (int) $user['ID'] ] ?? 0 ];
						$login    = (string) $user['user_login'];
						$email    = trim( (string) ( $user['user_email'] ?? '' ), " \t\n\r\0\x0B" );
						$existing = $db->column( 'SELECT `ID` FROM ' . $live( 'users' ) . ' WHERE `user_login` = ' . $db->quote( $login ) );
						if ( ! isset( $existing[0] ) && '' !== $email && in_array( 'user_email', $columns, true ) ) {
							// Same person under another login: one account per e-mail address, as WordPress expects.
							$existing = $db->column( 'SELECT `ID` FROM ' . $live( 'users' ) . ' WHERE `user_email` = ' . $db->quote( $email ) . ' ORDER BY `ID` LIMIT 1' );
						}
						if ( isset( $existing[0] ) ) {
							$id = (int) $existing[0];
							++$state['found'];
						} else {
							if ( in_array( 'user_nicename', $copy, true ) ) {
								$user['user_nicename'] = self::free_nicename( $db, $live( 'users' ), (string) ( $user['user_nicename'] ?? '' ), $login );
							}
							$names  = array();
							$values = array();
							foreach ( $copy as $column ) {
								if ( null !== ( $user[ $column ] ?? null ) ) { // A missing value takes the column's default (strict SQL modes refuse '' for dates).
									$names[]  = Connection::identifier( $column );
									$values[] = $db->quote( (string) $user[ $column ] );
								}
							}
							$db->query( 'INSERT INTO ' . $live( 'users' ) . ' (' . implode( ', ', $names ) . ') VALUES (' . implode( ', ', $values ) . ')' );
							$id = (int) $db->mysqli()->insert_id;
							++$state['added'];
							// A new user's profile comes along; per-site keys are handled below.
							$db->query(
								'INSERT INTO ' . $live( 'usermeta' ) . ' (`user_id`, `meta_key`, `meta_value`) SELECT ' . $id . ', `meta_key`, `meta_value` FROM ' . $tmp( 'usermeta' )
								. ' WHERE `user_id` = ' . (int) $user['ID'] . ' AND `meta_key` NOT IN (' . self::site_keys( $db, $to ) . ", 'primary_blog', 'source_domain')" . $foreign
							);
							$db->query( 'INSERT INTO ' . $live( 'usermeta' ) . " (`user_id`, `meta_key`, `meta_value`) VALUES ({$id}, 'primary_blog', '" . (int) $primary['blog_id'] . "'), ({$id}, 'source_domain', " . $db->quote( (string) $primary['domain'] ) . ')' );
						}
						$db->query( "REPLACE INTO {$map} (`old_id`, `new_id`) VALUES (" . (int) $user['ID'] . ", {$id})" );

						// Their role on each site: the backup's per-site keys (wp_capabilities, or wp_<old id>_capabilities) with the site's prefix.
						foreach ( $sites as $site ) {
							$from = $site['from'] > 1 ? $to . $site['from'] . '_' : $to;
							$into = $to . (int) $site['blog_id'] . '_';
							$keys = array();
							$case = '';
							foreach ( self::SITE_USER_KEYS as $key ) {
								$keys[] = $db->quote( $into . $key );
								$case  .= ' WHEN ' . $db->quote( $from . $key ) . ' THEN ' . $db->quote( $into . $key );
							}
							$db->query( 'DELETE FROM ' . $live( 'usermeta' ) . " WHERE `user_id` = {$id} AND `meta_key` IN (" . implode( ', ', $keys ) . ')' );
							$db->query(
								'INSERT INTO ' . $live( 'usermeta' ) . " (`user_id`, `meta_key`, `meta_value`) SELECT {$id}, CASE `meta_key`{$case} END, `meta_value` FROM " . $tmp( 'usermeta' )
								. ' WHERE `user_id` = ' . (int) $user['ID'] . ' AND `meta_key` IN (' . self::site_keys( $db, $from ) . ')'
							);
						}
					}
					$restore->set_progress( 'site-users', $users ? (string) json_encode( $state ) : 'done' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Internal progress record.
					$db->query( 'COMMIT' );
				} catch ( \Throwable $e ) {
					$db->query( 'ROLLBACK' );
					throw $e;
				}
			} while ( $users );
			$context->log( sprintf( 'Merged the backup\'s users into the network: %d already here with the same login or e-mail (kept as they are), %d added; all have their role on site %s.%s', (int) $state['found'], (int) $state['added'], implode( ', ', array_column( $sites, 'blog_id' ) ), $state['left'] ? sprintf( ' %d user(s) of sites left in the backup were not added.', (int) $state['left'] ) : '' ) );
		}

		// Authors, comment users and link owners follow the new IDs, a range of rows per transaction.
		// Someone who is not in the backup's users (deleted, or no users table) is nobody here, not whoever has that ID.
		$remap = array();
		foreach ( $sites as $site ) {
			foreach ( array(
				'posts'    => array( 'post_author', 'ID' ),
				'comments' => array( 'user_id', 'comment_ID' ),
				'links'    => array( 'link_owner', 'link_id' ),
			) as $name => $columns ) {
				$remap[ (int) $site['blog_id'] . '_' . $name ] = $columns;
			}
		}
		foreach ( $remap as $name => $columns ) {
			$table = RestoreDatabase::TMP . $name;
			$mark  = 'site-remap:' . $name;
			if ( ! isset( $tables[ $table ] ) || 'done' === $restore->progress( $mark ) ) {
				continue;
			}
			$has = $db->column( 'SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( $table ) . ' AND COLUMN_NAME IN (' . $db->quote( $columns[0] ) . ', ' . $db->quote( $columns[1] ) . ')' );
			if ( 2 !== (int) ( $has[0] ?? 0 ) ) {
				$restore->set_progress( $mark, 'done' );
				continue;
			}
			$col = Connection::identifier( $columns[0] );
			$key = Connection::identifier( $columns[1] );
			do {
				if ( ! $context->should_continue() ) {
					return false;
				}
				$after = (int) $restore->progress( $mark );
				$upto  = $db->column( 'SELECT MAX(`k`) FROM (SELECT ' . $key . ' AS `k` FROM ' . Connection::identifier( $table ) . " WHERE {$key} > {$after} ORDER BY {$key} LIMIT " . self::REMAP_BATCH . ') `r`' );
				$upto  = isset( $upto[0] ) ? (int) $upto[0] : 0;
				$db->query( 'START TRANSACTION' );
				try {
					if ( $upto > $after ) {
						$db->query( 'UPDATE ' . Connection::identifier( $table ) . " `t` LEFT JOIN {$map} `m` ON `t`.{$col} = `m`.`old_id` SET `t`.{$col} = COALESCE(`m`.`new_id`, 0) WHERE `t`.{$key} > {$after} AND `t`.{$key} <= {$upto}" );
					}
					$restore->set_progress( $mark, $upto > $after ? (string) $upto : 'done' );
					$db->query( 'COMMIT' );
				} catch ( \Throwable $e ) {
					$db->query( 'ROLLBACK' );
					throw $e;
				}
			} while ( $upto > $after );
		}
		$restore->drop( array( RestoreDatabase::TMP . 'users', RestoreDatabase::TMP . 'usermeta' ) ); // Never switched in: the network's users stay.
		return true;
	}

	/**
	 * Adds a new site to the network's list of sites, once its tables are live.
	 *
	 * @param RestoreDatabase     $restore Database helper.
	 * @param array<string,mixed> $plan    From resolve(), with blog_id set.
	 * @param array<string,mixed> $target  Restore target of the network (table_prefix, network).
	 * @param Context             $context Context.
	 * @return void
	 * @throws \Throwable After rolling back, when the insert fails.
	 */
	public static function register( RestoreDatabase $restore, array $plan, array $target, Context $context ): void {
		$new = array_values(
			array_filter(
				self::sites( $plan ),
				static function ( array $site ): bool {
					return ! empty( $site['new'] );
				}
			)
		);
		if ( ! $new || 'done' === $restore->progress( 'site-row' ) ) {
			return;
		}
		$db  = $restore->db();
		$now = gmdate( 'Y-m-d H:i:s' );
		$db->query( 'START TRANSACTION' );
		try {
			foreach ( $new as $site ) {
				$db->query(
					'INSERT IGNORE INTO ' . Connection::identifier( (string) ( $target['table_prefix'] ?? 'wp_' ) . 'blogs' ) . ' (`blog_id`, `site_id`, `domain`, `path`, `registered`, `last_updated`, `public`) VALUES ('
					. (int) $site['blog_id'] . ', ' . max( 1, (int) ( $target['network']['id'] ?? 1 ) ) . ', ' . $db->quote( (string) $site['domain'] ) . ', ' . $db->quote( (string) $site['path'] ) . ", '{$now}', '{$now}', 1)"
				);
			}
			$restore->set_progress( 'site-row', 'done' );
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			throw $e;
		}
		foreach ( $new as $site ) {
			$context->log( sprintf( 'Added site %d at %s%s to the network.', (int) $site['blog_id'], SubsiteExtract::printable( (string) $site['domain'] ), SubsiteExtract::printable( (string) $site['path'] ) ) );
		}
	}

	/**
	 * For each backup user of this batch with a role on a chosen site, or posts, comments or links on it: the index of the first such site.
	 *
	 * @param Connection                             $db     Connection.
	 * @param array<int,array{from:int,blog_id:int}> $sites  Chosen sites.
	 * @param string                                 $to     Network prefix (the keys have it by now).
	 * @param int[]                                  $ids    Backup user IDs.
	 * @param array<string,int>                      $tables Imported tables (flipped).
	 * @return array<int,int> Backup user ID => index in $sites.
	 */
	private static function chosen_roles( Connection $db, array $sites, string $to, array $ids, array $tables ): array {
		$list  = implode( ', ', array_map( 'intval', $ids ) );
		$found = array();
		foreach ( $sites as $index => $site ) {
			$from  = (int) $site['from'] > 1 ? $to . (int) $site['from'] . '_' : $to;
			$users = $db->column( 'SELECT DISTINCT `user_id` FROM ' . Connection::identifier( RestoreDatabase::TMP . 'usermeta' ) . " WHERE `user_id` IN ({$list}) AND `meta_key` = " . $db->quote( $from . 'capabilities' ) );
			foreach ( array(
				'posts'    => 'post_author',
				'comments' => 'user_id',
				'links'    => 'link_owner',
			) as $name => $column ) {
				$table = RestoreDatabase::TMP . (int) $site['blog_id'] . '_' . $name;
				$has   = isset( $tables[ $table ] ) ? $db->column( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( $table ) . ' AND COLUMN_NAME = ' . $db->quote( $column ) ) : array( 0 );
				if ( (int) ( $has[0] ?? 0 ) > 0 ) {
					$users = array_merge( $users, $db->column( 'SELECT DISTINCT ' . Connection::identifier( $column ) . ' FROM ' . Connection::identifier( $table ) . ' WHERE ' . Connection::identifier( $column ) . " IN ({$list})" ) );
				}
			}
			foreach ( $users as $user ) {
				$found += array( (int) $user => $index );
			}
		}
		return $found;
	}

	/**
	 * A nicename no network user has yet (author URLs must not clash), like wp_insert_user() makes one.
	 *
	 * @param Connection $db       Connection.
	 * @param string     $users    Quoted users table.
	 * @param string     $nicename The backup's nicename.
	 * @param string     $login    The login, when the nicename is empty.
	 * @return string
	 */
	private static function free_nicename( Connection $db, string $users, string $nicename, string $login ): string {
		$base = '' !== $nicename ? $nicename : substr( (string) preg_replace( '/[^a-z0-9_.@-]+/', '-', strtolower( $login ) ), 0, 50 );
		$base = '' !== $base ? $base : 'user';
		$name = $base;
		for ( $n = 2; $n < 1000; $n++ ) {
			$taken = $db->column( 'SELECT COUNT(*) FROM ' . $users . ' WHERE `user_nicename` = ' . $db->quote( $name ) );
			if ( 0 === (int) ( $taken[0] ?? 0 ) ) {
				return $name;
			}
			$suffix = '-' . $n;
			$name   = substr( $base, 0, 50 - strlen( $suffix ) ) . $suffix;
		}
		return $name;
	}

	/**
	 * Live tables of site N that the backup does not replace (they go aside with the replaced ones).
	 *
	 * @param string[] $live     Live tables with the network prefix.
	 * @param string[] $replaced Tables the restore puts live.
	 * @param string   $prefix   Network prefix.
	 * @param int      $blog     Site ID.
	 * @return string[]
	 */
	public static function leftovers( array $live, array $replaced, string $prefix, int $blog ): array {
		$own = $prefix . $blog . '_';
		return array_values(
			array_filter(
				array_diff( $live, $replaced ),
				static function ( string $table ) use ( $own ): bool {
					return 0 === strpos( $table, $own );
				}
			)
		);
	}

	/**
	 * URLs of the site: the backup's address and uploads URL.
	 *
	 * @param array<string,mixed> $plan   From resolve().
	 * @param array<string,mixed> $target Restore target of the network (home_url, uploads_url, uploads_dir).
	 * @return array{home_url:string,uploads_url:string,uploads_dir:string}
	 */
	public static function address( array $plan, array $target ): array {
		$home   = (string) ( $target['home_url'] ?? '' );
		$scheme = 0 === stripos( $home, 'http://' ) ? 'http' : 'https';
		$url    = $scheme . '://' . $plan['domain'] . rtrim( (string) $plan['path'], '/' );
		$up     = (string) ( $target['uploads_url'] ?? '' );
		$suffix = '' !== $home && 0 === strpos( $up, rtrim( $home, '/' ) . '/' ) ? substr( $up, strlen( rtrim( $home, '/' ) ) ) : '/wp-content/uploads';
		return array(
			'home_url'    => $url,
			'uploads_url' => $url . $suffix . '/sites/' . (int) $plan['blog_id'],
			'uploads_dir' => rtrim( (string) ( $target['uploads_dir'] ?? '' ), '/' ) . '/sites/' . (int) $plan['blog_id'],
		);
	}

	/**
	 * Makes an imported table InnoDB, so the author remap is all or nothing with its progress record.
	 *
	 * The network's own users tables are left as they are: adding a user
	 * twice cannot happen (a second run finds the login), so they need no
	 * transaction.
	 *
	 * @param Connection $db      Connection.
	 * @param string     $table   Table.
	 * @param Context    $context Context.
	 * @return void
	 */
	private static function innodb( Connection $db, string $table, Context $context ): void {
		$engine = $db->column( 'SELECT `ENGINE` FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote( $table ) );
		if ( isset( $engine[0] ) && 'innodb' !== strtolower( (string) $engine[0] ) ) {
			$db->query( 'ALTER TABLE ' . Connection::identifier( $table ) . ' ENGINE=InnoDB' );
			$context->log( sprintf( 'Converted %s from %s to InnoDB, so the user merge is all or nothing.', $table, (string) $engine[0] ) );
		}
	}

	/**
	 * The per-site user meta keys with a prefix, quoted for IN ().
	 *
	 * @param Connection $db     Connection.
	 * @param string     $prefix Prefix.
	 * @return string
	 */
	private static function site_keys( Connection $db, string $prefix ): string {
		return implode(
			', ',
			array_map(
				static function ( string $key ) use ( $db, $prefix ): string {
					return $db->quote( $prefix . $key );
				},
				self::SITE_USER_KEYS
			)
		);
	}

	/**
	 * A path as WordPress stores it for sites: "/", "/shop/".
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function slashed( string $path ): string {
		$path = trim( $path, '/' );
		return '' === $path ? '/' : '/' . $path . '/';
	}
}
