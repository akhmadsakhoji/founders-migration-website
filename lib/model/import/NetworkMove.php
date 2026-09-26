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

use Founders\Migration\Job\JobException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Where every site of a multisite network goes when the network is restored at another address.
 *
 * The main site follows the network: old domain and path become this
 * network's (DOMAIN_CURRENT_SITE and PATH_CURRENT_SITE in wp-config.php).
 * A subsite follows too when it lives under the network's address: in a
 * subdirectory (old.example/shop/ -> new.example/shop/) or on a subdomain
 * (shop.old.example -> shop.new.example, "www." ignored as WordPress does).
 * Any other domain, a subsite with its own domain, is kept unless it is
 * mapped with --map=<old>=<new>. Pure: no WordPress calls, unit-tested.
 */
final class NetworkMove {

	/**
	 * Parses --map=brand.example=brand.staging.example,other.example=other.test.
	 *
	 * @param string $value Comma-separated old=new domain pairs.
	 * @return array<string,string> Old domain => new domain.
	 * @throws \InvalidArgumentException On a malformed pair or domain.
	 */
	public static function parse_map( string $value ): array {
		$map = array();
		foreach ( preg_split( '/[\s,]+/', trim( $value, " \n\r\t\v\0" ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $pair ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- preg_split() fails only on a bad pattern.
			$parts = explode( '=', $pair );
			if ( 2 !== count( $parts ) || ! self::valid_domain( strtolower( $parts[0] ) ) || ! self::valid_domain( strtolower( $parts[1] ) ) ) {
				throw new \InvalidArgumentException( sprintf( 'Invalid --map entry "%s": use old-domain=new-domain, for example --map=brand.example=brand.staging.example.', $pair ) );
			}
			$map[ strtolower( $parts[0] ) ] = strtolower( $parts[1] );
		}
		return $map;
	}

	/**
	 * The source network: from the manifest, or worked out from its list of sites (backups made by development versions before 1.0.0).
	 *
	 * @param array<string,mixed> $site Manifest site.
	 * @return array{domain:string,path:string,subdomain:bool|null,main_site:int,networks:int} subdomain is null when unknown.
	 */
	public static function source( array $site ): array {
		$network = $site['network'] ?? null;
		if ( is_array( $network ) && self::valid_domain( strtolower( (string) ( $network['domain'] ?? '' ) ) ) && self::valid_path( self::slashed( (string) ( $network['path'] ?? '/' ) ) ) ) {
			return array(
				'domain'    => strtolower( (string) $network['domain'] ),
				'path'      => self::slashed( (string) ( $network['path'] ?? '/' ) ),
				'subdomain' => isset( $network['subdomain'] ) ? (bool) $network['subdomain'] : null,
				'main_site' => (int) ( $network['main_site'] ?? 1 ),
				'networks'  => max( 1, (int) ( $network['networks'] ?? 1 ) ),
			);
		}
		$sites = self::sites( $site );
		$main  = $sites[0] ?? array(
			'blog_id' => 1,
			'domain'  => self::domain_of( (string) ( $site['home_url'] ?? '' ) ),
			'path'    => self::path_of( (string) ( $site['home_url'] ?? '' ) ),
		);
		foreach ( $sites as $blog ) {
			if ( 1 === $blog['blog_id'] ) {
				$main = $blog;
			}
		}
		$base      = self::base( $main['domain'] );
		$subdomain = false;
		foreach ( $sites as $blog ) {
			if ( $blog['domain'] !== $main['domain'] && self::ends_with( $blog['domain'], '.' . $base ) ) {
				$subdomain = true;
			}
		}
		return array(
			'domain'    => $main['domain'],
			'path'      => $main['path'],
			'subdomain' => $subdomain,
			'main_site' => $main['blog_id'],
			'networks'  => 1,
		);
	}

	/**
	 * Plans the move.
	 *
	 * @param array<string,mixed>  $site   Manifest site (multisite, sites, network, home_url).
	 * @param array<string,mixed>  $target Restore target (home_url, network).
	 * @param array<string,string> $map    Extra old domain => new domain.
	 * @return array{network:array{from:array{domain:string,path:string},to:array{domain:string,path:string}},sites:array<int,array{blog_id:int,from:array{domain:string,path:string},to:array{domain:string,path:string}}>,urls:array<string,string>,kept:string[],moved:bool}
	 * @throws JobException When the network cannot be moved here.
	 */
	public static function plan( array $site, array $target, array $map = array() ): array {
		$from  = self::source( $site );
		$to    = self::target( $target );
		$sites = self::sites( $site );

		if ( ! self::valid_domain( $from['domain'] ) || count( $sites ) !== count( (array) ( $site['sites'] ?? array() ) ) ) {
			throw new JobException( 'The backup lists a site with an invalid domain or path; the network backup is refused.' );
		}
		if ( count( array_unique( array_column( $sites, 'network_id' ) ) ) > 1 ) {
			$from['networks'] = 2;
		}
		$problem = self::incompatible( $from, $to, count( $sites ) );
		if ( null !== $problem ) {
			throw new JobException( $problem );
		}
		if ( isset( $map[ $from['domain'] ] ) ) {
			throw new JobException( sprintf( 'The network\'s own domain %s follows this network (%s); leave it out of --map.', $from['domain'], $to['domain'] ) );
		}
		$unknown = array_diff( array_keys( $map ), array_column( $sites, 'domain' ) );
		if ( $unknown ) {
			throw new JobException( sprintf( 'No site of the backup has the domain %s; check --map.', implode( ', ', $unknown ) ) );
		}

		$scheme  = self::scheme( (string) ( $target['home_url'] ?? '' ) );
		$planned = array();
		$urls    = array();
		$kept    = array();
		$seen    = array();
		$moved   = $from['domain'] !== $to['domain'] || $from['path'] !== $to['path'];
		foreach ( $sites as $blog ) {
			$new = self::place( $blog, $from, $to, $map );
			if ( null === $new ) {
				$new    = array(
					'domain' => $blog['domain'],
					'path'   => $blog['path'],
				);
				$kept[] = $blog['domain'] . rtrim( $blog['path'], '/' );
			}
			$where = $new['domain'] . $new['path'];
			if ( isset( $seen[ $where ] ) ) {
				throw new JobException( sprintf( 'Sites %d and %d would both end up at %s; change --map.', $seen[ $where ], $blog['blog_id'], $where ) );
			}
			$seen[ $where ] = $blog['blog_id'];
			$planned[]      = array(
				'blog_id' => $blog['blog_id'],
				'from'    => array(
					'domain' => $blog['domain'],
					'path'   => $blog['path'],
				),
				'to'      => $new,
			);
			if ( $new['domain'] !== $blog['domain'] || $new['path'] !== $blog['path'] ) {
				$moved = true;
				$urls[ 'http://' . $blog['domain'] . rtrim( $blog['path'], '/' ) ] = $scheme . '://' . $new['domain'] . rtrim( $new['path'], '/' );
			}
		}

		return array(
			'network' => array(
				'from' => array(
					'domain' => $from['domain'],
					'path'   => $from['path'],
				),
				'to'   => array(
					'domain' => $to['domain'],
					'path'   => $to['path'],
				),
			),
			'sites'   => $planned,
			'urls'    => $urls,
			'kept'    => array_values( array_unique( $kept ) ),
			'moved'   => $moved,
		);
	}

	/**
	 * Why a network cannot be restored onto this one, or null when it can.
	 *
	 * Also used before a pull starts, with what the source reports about its network.
	 *
	 * @param array<string,mixed> $from  Source network (subdomain, main_site, networks).
	 * @param array<string,mixed> $to    This network (subdomain, main_site, networks).
	 * @param int                 $sites Number of sites in the source network.
	 * @return string|null
	 */
	public static function incompatible( array $from, array $to, int $sites ): ?string {
		if ( (int) ( $from['networks'] ?? 1 ) > 1 || (int) ( $to['networks'] ?? 1 ) > 1 ) {
			return 'Installs with more than one network are not supported yet.';
		}
		if ( (int) ( $from['main_site'] ?? 1 ) !== (int) ( $to['main_site'] ?? 1 ) ) {
			return sprintf( 'The source network\'s main site is site %1$d and this network\'s is site %2$d: set BLOG_ID_CURRENT_SITE to %1$d in wp-config.php and try again.', (int) ( $from['main_site'] ?? 1 ), (int) ( $to['main_site'] ?? 1 ) );
		}
		if ( $sites > 1 && isset( $from['subdomain'] ) && empty( $from['subdomain'] ) === ! empty( $to['subdomain'] ) ) { // Unknown kind (null): nothing to compare.
			return sprintf(
				'The source is a network with %s and this network uses %s. Converting between them is not supported: set SUBDOMAIN_INSTALL to %s in wp-config.php (on a network with only its main site) and try again.',
				empty( $from['subdomain'] ) ? 'subdirectories' : 'subdomains',
				empty( $to['subdomain'] ) ? 'subdirectories' : 'subdomains',
				empty( $from['subdomain'] ) ? 'false' : 'true'
			);
		}
		return null;
	}

	/**
	 * New domain and path of one site, or null when it keeps its own domain.
	 *
	 * @param array{blog_id:int,domain:string,path:string} $blog Site.
	 * @param array{domain:string,path:string}             $from Source network.
	 * @param array{domain:string,path:string}             $to   This network.
	 * @param array<string,string>                         $map  Extra old domain => new domain.
	 * @return array{domain:string,path:string}|null
	 */
	private static function place( array $blog, array $from, array $to, array $map ): ?array {
		$rest = 0 === strpos( $blog['path'], $from['path'] ) ? substr( $blog['path'], strlen( $from['path'] ) ) : null;
		if ( isset( $map[ $blog['domain'] ] ) ) {
			return array(
				'domain' => $map[ $blog['domain'] ],
				'path'   => $blog['path'],
			);
		}
		if ( $blog['domain'] === $from['domain'] && null !== $rest ) {
			return array(
				'domain' => $to['domain'],
				'path'   => $to['path'] . $rest,
			);
		}
		$base = self::base( $from['domain'] );
		if ( self::ends_with( $blog['domain'], '.' . $base ) ) {
			return array(
				'domain' => substr( $blog['domain'], 0, -strlen( $base ) ) . self::base( $to['domain'] ),
				'path'   => null === $rest ? $blog['path'] : $to['path'] . $rest,
			);
		}
		return null;
	}

	/**
	 * This network, from the restore target (or its home URL for jobs made before the network was recorded).
	 *
	 * @param array<string,mixed> $target Restore target.
	 * @return array{domain:string,path:string,subdomain:bool,main_site:int,networks:int}
	 */
	private static function target( array $target ): array {
		$network = $target['network'] ?? null;
		if ( is_array( $network ) && '' !== (string) ( $network['domain'] ?? '' ) ) {
			return array(
				'domain'    => strtolower( (string) $network['domain'] ),
				'path'      => self::slashed( (string) ( $network['path'] ?? '/' ) ),
				'subdomain' => ! empty( $network['subdomain'] ),
				'main_site' => (int) ( $network['main_site'] ?? 1 ),
				'networks'  => max( 1, (int) ( $network['networks'] ?? 1 ) ),
			);
		}
		$home = (string) ( $target['home_url'] ?? '' );
		return array(
			'domain'    => self::domain_of( $home ),
			'path'      => self::path_of( $home ),
			'subdomain' => false,
			'main_site' => 1,
			'networks'  => 1,
		);
	}

	/**
	 * Sites of the manifest, cleaned up.
	 *
	 * @param array<string,mixed> $site Manifest site.
	 * @return array<int,array{blog_id:int,domain:string,path:string,network_id:int}>
	 */
	private static function sites( array $site ): array {
		$sites = array();
		foreach ( (array) ( $site['sites'] ?? array() ) as $blog ) {
			if ( ! is_array( $blog ) || ! self::valid_domain( strtolower( (string) ( $blog['domain'] ?? '' ) ) ) || (int) ( $blog['blog_id'] ?? 0 ) < 1 || ! self::valid_path( self::slashed( (string) ( $blog['path'] ?? '/' ) ) ) ) {
				continue;
			}
			$sites[] = array(
				'blog_id'    => (int) $blog['blog_id'],
				'domain'     => strtolower( (string) $blog['domain'] ),
				'path'       => self::slashed( (string) ( $blog['path'] ?? '/' ) ),
				'network_id' => (int) ( $blog['network_id'] ?? 0 ),
			);
		}
		return $sites;
	}

	/**
	 * Domain (with a non-default port) of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function domain_of( string $url ): string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Library code must not depend on WordPress.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Path of a URL, with slashes on both ends.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function path_of( string $url ): string {
		return self::slashed( (string) parse_url( $url, PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Library code must not depend on WordPress.
	}

	/**
	 * Scheme of the target's address.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function scheme( string $url ): string {
		return 0 === stripos( $url, 'http://' ) ? 'http' : 'https';
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

	/**
	 * Domain without "www.", the base of subdomain sites.
	 *
	 * @param string $domain Domain.
	 * @return string
	 */
	private static function base( string $domain ): string {
		return 0 === strpos( $domain, 'www.' ) ? substr( $domain, 4 ) : $domain;
	}

	/**
	 * Whether $haystack ends with $needle.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	private static function ends_with( string $haystack, string $needle ): bool {
		return strlen( $haystack ) > strlen( $needle ) && substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	/**
	 * Whether $domain is a host name or address, optionally with a port.
	 *
	 * @param string $domain Domain, lowercase.
	 * @return bool
	 */
	private static function valid_domain( string $domain ): bool {
		return 1 === preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?$/D', $domain );
	}

	/**
	 * Whether $path is a site path as WordPress makes them ("/", "/shop/").
	 *
	 * @param string $path Path, slashed.
	 * @return bool
	 */
	private static function valid_path( string $path ): bool {
		return 1 === preg_match( '#^/([A-Za-z0-9._~%-]+/)*$#D', $path );
	}
}
