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

use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Ownership fixes need lchown() and friends on the site's own files.

/**
 * Makes a restored site work on its server, as a restore on a fresh
 * CyberPanel / OpenLiteSpeed or Apache site otherwise needs by hand:
 *
 * 1. rewrite   - the permalink rules in the root .htaccess, added when they are
 *                missing (a backup never carries the root .htaccess);
 * 2. elementor - Elementor's generated CSS and element caches are emptied, so
 *                they are rebuilt with the site's new URLs;
 * 3. purge     - the LiteSpeed page cache is purged on the next uncached request;
 * 4. owner     - files a restore run as root (WP-CLI) wrote go back to the
 *                site's owner, so PHP can write uploads and updates;
 * 5. probe     - an unknown address is requested from this server: when the web
 *                server answers with its own 404 page instead of WordPress, the
 *                job notes how to make it read .htaccess.
 *
 * Nothing here can fail a restore: problems become notes in $job->data['notes'],
 * shown after the restore. The `fmwp_server_fixes` filter can switch fixes off.
 * Each call does one unit of work (a phase, a site, a slice of the walk), so
 * the Runner checkpoints between them.
 */
final class ServerFixStep implements Step {

	const PHASES = array( 'rewrite', 'elementor', 'purge', 'owner', 'probe' );

	/** Site option asking for a LiteSpeed page cache purge on the next web request. */
	const PURGE_OPTION = 'fmwp_purge_page_cache';

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Server';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 */
	public function run( Job $job, Context $context ): bool {
		$cursor = $job->cursor;
		$phase  = (int) ( $cursor['phase'] ?? 0 );
		if ( $phase >= count( self::PHASES ) ) {
			return true;
		}
		$name = self::PHASES[ $phase ];

		/**
		 * Filters which fixes run after a restore.
		 *
		 * @param string[] $fixes Any of rewrite, elementor, purge, owner, probe.
		 * @param Job      $job   The restore job.
		 */
		$enabled = (array) apply_filters( 'fmwp_server_fixes', self::PHASES, $job );

		$done = true;
		if ( in_array( $name, $enabled, true ) ) {
			try {
				switch ( $name ) {
					case 'rewrite':
						self::rewrite( $job, $context );
						break;
					case 'elementor':
						$done = self::elementor( $job, $context, $cursor );
						break;
					case 'purge':
						self::purge( $context );
						break;
					case 'owner':
						$done = self::owner( $job, $context, $cursor );
						break;
					case 'probe':
						self::probe( $job, $context );
						break;
				}
			} catch ( \Throwable $e ) {
				self::note( $job, $context, sprintf( 'After the restore, "%s" was skipped: %s', $name, $e->getMessage() ) );
				$done = true;
			}
		}

		$job->cursor = $done ? array( 'phase' => $phase + 1 ) : array( 'phase' => $phase ) + $cursor;
		return $done && $phase + 1 >= count( self::PHASES );
	}

	/**
	 * Adds a note shown after the restore (CLI and browser) and to the log.
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @param string  $note    Note.
	 * @return void
	 */
	private static function note( Job $job, Context $context, string $note ): void {
		$notes = (array) ( $job->data['notes'] ?? array() );
		if ( ! in_array( $note, $notes, true ) ) {
			$notes[]            = $note;
			$job->data['notes'] = $notes;
		}
		$context->log( $note );
	}

	// ----------------------------------------------------------------- rewrite

	/**
	 * Adds WordPress's rewrite rules to the root .htaccess when they are missing.
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @return void
	 */
	private static function rewrite( Job $job, Context $context ): void {
		if ( ! empty( $GLOBALS['is_nginx'] ) || ! empty( $GLOBALS['is_IIS'] ) ) {
			return; // Those servers do not read .htaccess (known in browser restores only).
		}
		if ( ! is_multisite() && '' === (string) get_option( 'permalink_structure' ) ) {
			return; // Plain permalinks need no rules.
		}
		$home    = untrailingslashit( set_url_scheme( (string) get_option( 'home' ), 'http' ) );
		$siteurl = untrailingslashit( set_url_scheme( (string) get_option( 'siteurl' ), 'http' ) );
		if ( 0 !== strcasecmp( $home, $siteurl ) ) {
			self::note( $job, $context, 'WordPress runs from its own folder under the site address, so its .htaccess was not checked: if pages answer 404, save Settings > Permalinks once.' );
			return;
		}

		$file     = ABSPATH . '.htaccess';
		$existing = is_file( $file ) ? (string) file_get_contents( $file ) : '';
		if ( self::has_rules( $existing ) ) {
			return;
		}

		$lines = is_multisite() ? self::network_rules() : self::site_rules();
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( insert_with_markers( $file, 'WordPress', $lines ) ) {
			$context->log( sprintf( 'Added the WordPress rewrite rules to %s.', $file ) );
			return;
		}
		self::note( $job, $context, sprintf( 'The WordPress rewrite rules are missing from %s and it cannot be written: pages will answer 404 until they are added (Settings > Permalinks shows them).', $file ) );
	}

	/**
	 * Whether .htaccess contents already hold WordPress's rules: a rewrite to index.php, inside the
	 * "WordPress" markers or pasted from the network setup. An empty marker block (WordPress writes
	 * one while permalinks are plain) does not count.
	 *
	 * @param string $contents File contents.
	 * @return bool
	 */
	public static function has_rules( string $contents ): bool {
		return 1 === preg_match( '~^[ \t]*RewriteRule\s+\.\s+\S*index\.php\s+\[[^\]]*L[^\]]*\]~mi', $contents );
	}

	/**
	 * Single-site rules, as WordPress writes them (WP_Rewrite::mod_rewrite_rules()).
	 *
	 * @param string|null $base Path of the site address, with slashes (default from home_url()).
	 * @return string[]
	 */
	public static function site_rules( ?string $base = null ): array {
		$base = $base ?? trailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$base = '/' . ltrim( $base, '/' );
		return array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
			'RewriteBase ' . $base,
			'RewriteRule ^index\.php$ - [L]',
			'RewriteCond %{REQUEST_FILENAME} !-f',
			'RewriteCond %{REQUEST_FILENAME} !-d',
			'RewriteRule . ' . $base . 'index.php [L]',
			'</IfModule>',
		);
	}

	/**
	 * Network rules, as the Network Setup screen gives them (network_step2()).
	 *
	 * @param bool|null   $subdomains  Subdomain install (default from is_subdomain_install()).
	 * @param string|null $base        Path of the network (default from the current network).
	 * @param bool|null   $files_rules Old networks serving uploads through ms-files.php.
	 * @return string[]
	 */
	public static function network_rules( ?bool $subdomains = null, ?string $base = null, ?bool $files_rules = null ): array {
		$subdomains  = $subdomains ?? is_subdomain_install();
		$base        = $base ?? ( function_exists( 'get_network' ) && get_network() ? get_network()->path : '/' );
		$base        = '/' . trim( $base, '/' ) . '/';
		$base        = '//' === $base ? '/' : $base;
		$files_rules = $files_rules ?? (bool) get_site_option( 'ms_files_rewriting' );
		$match       = $subdomains ? '' : '([_0-9a-zA-Z-]+/)?';
		$repl_01     = $subdomains ? '' : '$1';
		$repl_12     = $subdomains ? '$1' : '$2';

		$lines = array(
			'RewriteEngine On',
			'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
			'RewriteBase ' . $base,
			'RewriteRule ^index\.php$ - [L]',
		);
		if ( $files_rules ) {
			$lines[] = '# uploaded files';
			$lines[] = 'RewriteRule ^' . $match . 'files/(.+) wp-includes/ms-files.php?file=' . $repl_12 . ' [L]';
		}
		return array_merge(
			$lines,
			array(
				'# add a trailing slash to /wp-admin',
				'RewriteRule ^' . $match . 'wp-admin$ ' . $repl_01 . 'wp-admin/ [R=301,L]',
				'RewriteCond %{REQUEST_FILENAME} -f [OR]',
				'RewriteCond %{REQUEST_FILENAME} -d',
				'RewriteRule ^ - [L]',
				'RewriteRule ^' . $match . '(wp-(content|admin|includes).*) ' . $repl_12 . ' [L]',
				'RewriteRule ^' . $match . '(.*\.php)$ ' . $repl_12 . ' [L]',
				'RewriteRule . index.php [L]',
			)
		);
	}

	// --------------------------------------------------------------- elementor

	/**
	 * Empties Elementor's generated CSS and element caches (its "Clear Files & Data") on one
	 * site per call, so they are rebuilt with the restored site's URLs.
	 *
	 * @param Job                 $job     Job.
	 * @param Context             $context Context.
	 * @param array<string,mixed> $cursor  Cursor (index of the next site, sites cleared).
	 * @return bool Whether every site is done.
	 */
	private static function elementor( Job $job, Context $context, array &$cursor ): bool {
		global $wpdb;
		if ( ! isset( $cursor['sites'] ) ) {
			$cursor['sites'] = self::sites( $job );
		}
		$sites = array_map( 'intval', (array) $cursor['sites'] );
		$index = (int) ( $cursor['site'] ?? 0 );
		if ( $index >= count( $sites ) ) {
			if ( ! empty( $cursor['cleared'] ) ) {
				$context->log( sprintf( 'Emptied Elementor\'s generated CSS on %d site(s); it is rebuilt on the next visit.', (int) $cursor['cleared'] ) );
			}
			return true;
		}

		$site     = $sites[ $index ];
		$switched = is_multisite() && get_current_blog_id() !== $site;
		if ( $switched ) {
			switch_to_blog( $site );
		}
		try {
			if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'elementor_version' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Straight after the switch of tables, no cache.
				$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_css', '_elementor_element_cache')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As Elementor's own clear_cache().
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN ('_elementor_global_css', 'elementor-custom-breakpoints-files')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- As above.
				$uploads = wp_upload_dir( null, false, true );
				foreach ( (array) glob( trailingslashit( (string) $uploads['basedir'] ) . 'elementor/css/*', GLOB_NOSORT ) as $file ) {
					if ( is_string( $file ) && is_file( $file ) && ! is_link( $file ) ) {
						wp_delete_file( $file );
					}
				}
				wp_cache_delete( 'alloptions', 'options' );
				$cursor['cleared'] = (int) ( $cursor['cleared'] ?? 0 ) + 1;
			}
		} catch ( \Throwable $e ) {
			$context->log( sprintf( 'Elementor\'s CSS on site %d was not emptied: %s', $site, $e->getMessage() ) );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		$cursor['site'] = $index + 1;
		return false;
	}

	/**
	 * The sites this restore changed: the imported ones, every site of a network, or this site.
	 *
	 * @param Job $job Job.
	 * @return int[]
	 */
	private static function sites( Job $job ): array {
		if ( ! is_multisite() ) {
			return array( get_current_blog_id() );
		}
		if ( is_array( $job->data['import'] ?? null ) ) {
			return array_map( 'intval', array_column( SubsiteImport::sites( $job->data['import'] ), 'blog_id' ) );
		}
		$ids = array_map(
			'intval',
			get_sites(
				array(
					'fields'  => 'ids',
					'number'  => 0,
					'orderby' => 'id',
				)
			)
		);
		sort( $ids );
		return $ids;
	}

	// ------------------------------------------------------------------- purge

	/**
	 * Purges the LiteSpeed page cache: now through LiteSpeed Cache when it is loaded, and with a
	 * purge header on the next uncached web request (a restore over a site keeps its cached pages).
	 *
	 * @param Context $context Context.
	 * @return void
	 */
	private static function purge( Context $context ): void {
		update_site_option( self::PURGE_OPTION, time() );
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
		}
		$context->log( 'The LiteSpeed page cache is purged on the next uncached request (opening wp-admin is enough).' );
	}

	/**
	 * On the next web request after a restore, asks LiteSpeed to purge this site's page cache.
	 *
	 * @return void
	 */
	public static function send_pending_purge(): void {
		if ( 'cli' === PHP_SAPI || ! get_site_option( self::PURGE_OPTION ) ) {
			return;
		}
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		if ( false !== stripos( $software, 'litespeed' ) ) {
			if ( headers_sent() ) {
				return; // Try again on the next request.
			}
			header( 'X-LiteSpeed-Purge: *', false );
		}
		delete_site_option( self::PURGE_OPTION );
	}

	// ------------------------------------------------------------------- owner

	/**
	 * The owner (uid, gid) this site's files should have, when this process runs as root and the
	 * site belongs to someone else: the owner and group of wp-config.php (where WordPress looks for
	 * it), else the owner of the site folder with that user's primary group.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function owner_target(): ?array {
		if ( ! function_exists( 'posix_geteuid' ) || 0 !== posix_geteuid() ) {
			return null;
		}
		clearstatcache();
		$config = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $config ) ) {
			$parent = dirname( ABSPATH ) . '/wp-config.php';
			$config = file_exists( $parent ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ? $parent : '';
		}
		if ( '' !== $config && (int) @fileowner( $config ) > 0 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 0 when unknown.
			return array( (int) fileowner( $config ), (int) filegroup( $config ) );
		}
		$uid = (int) @fileowner( rtrim( ABSPATH, '/' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 0 when unknown.
		if ( $uid > 0 ) {
			$user = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $uid ) : false;
			return array( $uid, is_array( $user ) ? (int) $user['gid'] : (int) filegroup( rtrim( ABSPATH, '/' ) ) );
		}
		return null;
	}

	/**
	 * The folders to walk: where this restore writes (wp-content, uploads, plugins, themes,
	 * must-use plugins) and FMW's own folders, by their real paths. A folder is taken only when it
	 * lies in the site's home folder (the parent of the WordPress folder) or already belongs to the
	 * site's owner, so a shared or system folder is never walked.
	 *
	 * FMW's own folders are also taken when this restore created them (owned by root, new) in a
	 * folder of the site's owner.
	 *
	 * @param Job $job   Job.
	 * @param int $uid   Site owner.
	 * @param int $since Start of the restore.
	 * @return array<int,array{0:string,1:int,2:int}> Path, device and inode of each root.
	 */
	private static function owner_roots( Job $job, int $uid, int $since ): array {
		$home   = (string) realpath( dirname( ABSPATH ) );
		$target = (array) ( $job->options['target'] ?? array() );
		$own    = array( fmwp_backups_path(), fmwp_storage_path() );
		$paths  = array_merge( array( WP_CONTENT_DIR, $target['content_dir'] ?? '', $target['uploads_dir'] ?? '', $target['plugins_dir'] ?? '', $target['mu_plugins_dir'] ?? '', $target['themes_dir'] ?? '' ), $own );
		$roots  = array();
		foreach ( $paths as $path ) {
			$real = '' === (string) $path ? false : realpath( (string) $path );
			if ( false === $real || ! is_dir( $real ) || substr_count( trim( $real, '/' ), '/' ) < 1 ) {
				continue; // Missing, or / and top-level folders such as /home.
			}
			$stat   = lstat( $real );
			$inside = '' !== $home && '/' !== $home && 0 === strpos( $real . '/', $home . '/' );
			$made   = false !== $stat && in_array( $path, $own, true ) && 0 === (int) $stat['uid'] && (int) $stat['ctime'] >= $since && (int) @fileowner( dirname( $real ) ) === $uid; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 0 when unknown.
			if ( false === $stat || ( ! $inside && ! $made && (int) $stat['uid'] !== $uid ) ) {
				continue;
			}
			foreach ( $roots as $root ) {
				if ( 0 === strpos( $real . '/', $root[0] . '/' ) ) {
					continue 2; // Already inside another root.
				}
			}
			$roots[] = array( $real, (int) $stat['dev'], (int) $stat['ino'] );
		}
		return $roots;
	}

	/**
	 * Gives the files this restore wrote as root to the site's owner, one folder per loop turn,
	 * in slices. Only entries owned by root and changed since the job started are touched; links
	 * are changed themselves (lchown), never followed; files with several hard links, folders on
	 * another device and folders owned by someone else are left alone. Each folder is entered with
	 * chdir() and checked (device, inode) before its entries are handled by relative names, so a
	 * folder whose path is swapped for a link, before or while it is handled, cannot redirect the walk.
	 *
	 * @param Job                 $job     Job.
	 * @param Context             $context Context.
	 * @param array<string,mixed> $cursor  Cursor (folders still to walk, count so far).
	 * @return bool Whether the walk is complete.
	 */
	private static function owner( Job $job, Context $context, array &$cursor ): bool {
		$target = self::owner_target();
		if ( null === $target ) {
			return true; // Not root, or the site belongs to root as well.
		}
		list( $uid, $gid ) = $target;
		$since             = (int) $job->created_at - 60;

		if ( ! isset( $cursor['dirs'] ) ) {
			$cursor['dirs']    = array();
			$cursor['changed'] = 0;
			foreach ( self::owner_roots( $job, $uid, $since ) as $root ) {
				$cursor['dirs'][] = array( $root[0], $root[1], $root[2] );
			}
			$htaccess = ABSPATH . '.htaccess';
			if ( self::give( $htaccess, $uid, $gid, $since, null ) ) {
				++$cursor['changed'];
			}
		}

		$cwd = getcwd();
		try {
			while ( $cursor['dirs'] ) {
				list( $dir, $dev, $ino ) = array_pop( $cursor['dirs'] );
				$dir                     = (string) $dir;
				clearstatcache( true );
				// Work inside the folder by relative names: once in it, a swap of its path for a link changes nothing.
				if ( ! @chdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing folders are skipped.
					continue;
				}
				$here = @stat( '.' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- As above.
				if ( false === $here || (int) $here['dev'] !== (int) $dev || (int) $here['ino'] !== (int) $ino || ! in_array( (int) $here['uid'], array( 0, $uid ), true ) ) {
					continue; // Swapped for a link, replaced, or not the site's.
				}
				if ( self::give( '.', $uid, $gid, $since, (int) $dev ) ) {
					++$cursor['changed'];
				}
				$entries = @scandir( '.', SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable folders are skipped.
				foreach ( (array) $entries as $entry ) {
					if ( ! is_string( $entry ) || '.' === $entry || '..' === $entry ) {
						continue;
					}
					$info = @lstat( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Vanished entries are skipped.
					if ( false === $info ) {
						continue;
					}
					if ( 0040000 === ( (int) $info['mode'] & 0170000 ) ) {
						if ( (int) $info['dev'] === (int) $dev ) {
							$cursor['dirs'][] = array( $dir . '/' . $entry, (int) $info['dev'], (int) $info['ino'] );
						}
					} elseif ( self::give( $entry, $uid, $gid, $since, (int) $dev ) ) {
						++$cursor['changed'];
					}
				}
				if ( $cursor['dirs'] && ! $context->should_continue() ) {
					return false;
				}
			}
		} finally {
			if ( false !== $cwd ) {
				@chdir( $cwd ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
			}
		}
		$user = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $uid ) : false;
		$context->log( sprintf( 'Gave %d files and folders this restore wrote as root to %s.', (int) $cursor['changed'], is_array( $user ) ? $user['name'] : 'uid ' . $uid ) );
		return true;
	}

	/**
	 * Gives one entry to uid:gid when root owns it and it changed since $since (lchown: a link is
	 * changed itself, never followed). Files with several hard links are left alone.
	 *
	 * @param string   $path  Path.
	 * @param int      $uid   User ID.
	 * @param int      $gid   Group ID.
	 * @param int      $since Oldest change time to touch.
	 * @param int|null $dev   Device the entry must be on (null: any).
	 * @return bool Whether it changed.
	 */
	private static function give( string $path, int $uid, int $gid, int $since, ?int $dev ): bool {
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing entries are skipped.
		if ( false === $stat || 0 !== (int) $stat['uid'] || (int) $stat['ctime'] < $since || ( null !== $dev && (int) $stat['dev'] !== $dev ) ) {
			return false;
		}
		$is_dir = 0040000 === ( (int) $stat['mode'] & 0170000 );
		if ( ! $is_dir && (int) $stat['nlink'] > 1 ) {
			return false;
		}
		return @lchown( $path, $uid ) && @lchgrp( $path, $gid ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
	}

	// ------------------------------------------------------------------- probe

	/**
	 * Requests an address that does not exist from this server: WordPress should answer it.
	 * The web server's own 404 page means it did not apply the .htaccess rules.
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @return void
	 */
	private static function probe( Job $job, Context $context ): void {
		if ( ! is_multisite() && '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}
		$url  = home_url( '/fmw-rewrite-check-' . wp_generate_password( 8, false ) . '/' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host || ! function_exists( 'curl_init' ) ) {
			return;
		}
		$ports = array_unique( array_filter( array( 80, 443, (int) wp_parse_url( $url, PHP_URL_PORT ) ) ) );
		$pins  = array_map(
			static function ( $port ) use ( $host ) {
				return $host . ':' . $port . ':127.0.0.1';
			},
			$ports
		);
		// Ask this server, whatever the DNS says (the domain may still point to the old server) and without a proxy.
		$resolve = static function ( $handle ) use ( $pins ) {
			if ( defined( 'CURLOPT_RESOLVE' ) ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, $pins ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Only way to pin the address for this request.
				curl_setopt( $handle, CURLOPT_PROXY, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- As above.
			}
		};
		add_action( 'http_api_curl', $resolve );
		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'sslverify'   => false, // This server, by its local address.
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
		} finally {
			remove_action( 'http_api_curl', $resolve );
		}
		if ( is_wp_error( $response ) ) {
			$context->log( 'Permalink check skipped: this server did not answer on 127.0.0.1 (' . $response->get_error_message() . ').' );
			return;
		}
		$code   = (int) wp_remote_retrieve_response_code( $response );
		$server = (string) wp_remote_retrieve_header( $response, 'server' );
		$kind   = self::server_404( $code, $server, (string) wp_remote_retrieve_body( $response ) );
		if ( 'litespeed' === $kind ) {
			$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			self::note( $job, $context, sprintf( 'Pages answer with LiteSpeed\'s own 404 page: the web server has not applied the .htaccess rules. On OpenLiteSpeed (CyberPanel), make sure the rewrite block of /usr/local/lsws/conf/vhosts/%s/vhost.conf has "autoLoadHtaccess 1", then restart it: systemctl restart lsws.', $domain ) );
		} elseif ( 'apache' === $kind ) {
			self::note( $job, $context, 'Pages answer with Apache\'s own 404 page: mod_rewrite is off or "AllowOverride" does not allow the .htaccess rules for this site.' );
		} elseif ( $code >= 300 && $code < 400 ) {
			$context->log( sprintf( 'Permalink check: HTTP %d, redirected to %s; not checked further.', $code, (string) wp_remote_retrieve_header( $response, 'location' ) ) );
		} else {
			$context->log( sprintf( 'Permalink check: HTTP %d, answered by WordPress.', $code ) );
		}
	}

	/**
	 * Recognises a web server's own 404 page (not WordPress's).
	 *
	 * @param int    $code   HTTP status.
	 * @param string $server Server header.
	 * @param string $body   Body.
	 * @return string "litespeed", "apache" or "" (WordPress, or not recognised).
	 */
	public static function server_404( int $code, string $server, string $body ): string {
		if ( 404 !== $code ) {
			return '';
		}
		if ( false !== stripos( $body, 'LiteSpeed Technologies' ) || false !== stripos( $body, 'Proudly powered by LiteSpeed' ) ) {
			return 'litespeed';
		}
		if ( false !== stripos( $body, 'was not found on this server' ) && ( false !== stripos( $server, 'apache' ) || false !== stripos( $body, '<address>Apache' ) ) ) {
			return 'apache';
		}
		return '';
	}
}
