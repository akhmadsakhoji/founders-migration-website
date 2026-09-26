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

use Founders\Migration\Archive\ArchiveException;
use Founders\Migration\Archive\WpressDecoder;
use Founders\Migration\Archive\WpressPackage;
use Founders\Migration\Archive\WpressReader;
use Founders\Migration\Job\JobException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * The multisite.json of a .wpress network backup (All-in-One WP Migration Multisite Extension).
 *
 * "Network": true marks a whole network; false marks sites picked one by
 * one, whose tables use other placeholders (SERVMASK_PREFIX_mainsite_ for
 * users and the network's tables, SERVMASK_PREFIX_basesite_ for the main
 * site's, SERVMASK_PREFIX_<id>_ for the others; see SubsiteExtract). One
 * site of either kind restores onto a single site (extract()). Sites[] lists every site with its BlogID, Domain,
 * Path and the plugins and theme it had active: the export blanks those in
 * the database, so the restore puts them back from here (see
 * WpressActivation). The top-level Plugins are the network-activated ones.
 * Pure: no WordPress calls, unit-tested.
 */
final class WpressNetwork {

	/**
	 * Decoded multisite.json.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Decoded multisite.json.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Finds, decodes and parses the archive's multisite.json, or null when there is none.
	 *
	 * Unlike package.json it is encrypted and compressed with the rest of
	 * the archive (checked with 7.85 and 7.111), unless it reads as JSON as is.
	 *
	 * @param string      $path        Archive path.
	 * @param string|null $key         Decryption key of an encrypted archive.
	 * @param string      $compression Compression (package.json).
	 * @param int|null    $offset      Header offset of multisite.json when known (the check step's scan).
	 * @return self|null
	 * @throws ArchiveException When it is damaged or cannot be decrypted.
	 */
	public static function read( string $path, ?string $key, string $compression, ?int $offset = null ): ?self {
		$reader = WpressReader::open( $path, (int) $offset );
		try {
			$entry = $reader->next();
			if ( null === $offset ) {
				// Written right after package.json: looking further would read every header of a large single-site archive.
				while ( null !== $entry && 'package.json' === $entry->name ) {
					$entry = $reader->next();
				}
			}
			if ( null === $entry || 'multisite.json' !== $entry->name ) {
				return null;
			}
			if ( $entry->size > 16777216 ) {
				throw new ArchiveException( 'The archive\'s multisite.json is larger than 16 MB; it is refused.' );
			}
			$offset = $entry->offset;
			$stored = $reader->read( $entry->size );
		} finally {
			$reader->close();
		}
		$decoder = new WpressDecoder( $key, $compression );
		if ( ! $decoder->transforms() || is_array( json_decode( $stored, true ) ) ) {
			return self::parse( $stored );
		}
		if ( null === $key && WpressPackage::read( $path )->encrypted() ) {
			throw new ArchiveException( 'This network backup is encrypted: its list of sites needs the password.' );
		}
		$reader = WpressReader::open( $path, $offset );
		$json   = '';
		try {
			$entry    = $reader->next();
			$consumed = 0;
			if ( null === $entry ) {
				throw new ArchiveException( 'The archive changed while it was read.' );
			}
			$decoder->decode(
				$reader,
				$entry,
				false,
				$consumed,
				static function ( string $data ) use ( &$json ): void {
					$json .= $data;
					if ( strlen( $json ) > 16777216 ) {
						throw new ArchiveException( 'The archive\'s multisite.json is larger than 16 MB once decoded; it is refused.' );
					}
				},
				static function (): bool {
					return true;
				}
			);
		} finally {
			$reader->close();
		}
		return self::parse( $json );
	}

	/**
	 * Whether the archive has a multisite.json (a network backup), without reading past its first entries.
	 *
	 * @param string $path Archive path.
	 * @return bool
	 * @throws ArchiveException When the archive is damaged.
	 */
	public static function present( string $path ): bool {
		$reader = WpressReader::open( $path );
		try {
			$entry = $reader->next();
			while ( null !== $entry && 'package.json' === $entry->name ) {
				$entry = $reader->next();
			}
			return null !== $entry && 'multisite.json' === $entry->name;
		} finally {
			$reader->close();
		}
	}

	/**
	 * Parses and checks multisite.json.
	 *
	 * @param string $json Contents.
	 * @return self
	 * @throws ArchiveException When it is damaged.
	 */
	public static function parse( string $json ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['Sites'] ) || ! is_array( $data['Sites'] ) || ! $data['Sites'] ) {
			throw new ArchiveException( 'The archive\'s multisite.json is damaged: it lists no sites.' );
		}
		foreach ( (array) ( $data['Networks'] ?? array() ) as $network ) {
			if ( ! is_array( $network ) || ! is_string( $network['Domain'] ?? null ) || ! is_string( $network['Path'] ?? null ) ) {
				throw new ArchiveException( 'The archive\'s multisite.json is damaged: a network has no Domain or Path.' );
			}
		}
		foreach ( $data['Sites'] as $site ) {
			if ( ! is_array( $site ) || (int) ( $site['BlogID'] ?? 0 ) < 1 || ! is_string( $site['Domain'] ?? null ) || ! is_string( $site['Path'] ?? null ) ) {
				throw new ArchiveException( 'The archive\'s multisite.json is damaged: a site has no BlogID, Domain or Path.' );
			}
		}
		return new self( $data );
	}

	/**
	 * Whether this is a whole network, not sites picked one by one.
	 *
	 * @return bool
	 */
	public function is_network(): bool {
		return ! empty( $this->data['Network'] );
	}

	/**
	 * Number of sites.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( (array) $this->data['Sites'] );
	}

	/**
	 * The backup's site in the terms of an .fmw manifest (site.multisite, sites, network), for NetworkMove.
	 *
	 * The network's kind is not recorded: it is worked out from the sites
	 * (a site on a subdomain of the network's domain means subdomains).
	 *
	 * @param WpressPackage $package The archive's package.json.
	 * @return array<string,mixed>
	 */
	public function site( WpressPackage $package ): array {
		$sites = array();
		foreach ( (array) $this->data['Sites'] as $site ) {
			$sites[] = array(
				'blog_id'    => (int) $site['BlogID'],
				'domain'     => strtolower( (string) $site['Domain'] ),
				'path'       => (string) $site['Path'],
				'network_id' => (int) ( $site['SiteID'] ?? 1 ),
			);
		}
		$networks = array_values( array_filter( (array) ( $this->data['Networks'] ?? array() ), 'is_array' ) );
		$main     = $this->main_site();
		$home     = (string) ( $package->data()['HomeURL'] ?? '' );
		$manifest = array(
			'home_url'     => $home,
			'site_url'     => (string) ( $package->data()['SiteURL'] ?? $home ),
			'abspath'      => $package->wordpress( 'Absolute' ),
			'table_prefix' => $package->table_prefix(),
			'multisite'    => true,
			'sites'        => $sites,
		);
		$inferred = NetworkMove::source( $manifest ); // Kind, from the sites.
		$domain   = isset( $networks[0]['Domain'] ) ? strtolower( (string) $networks[0]['Domain'] ) : $inferred['domain'];
		$path     = isset( $networks[0]['Path'] ) ? (string) $networks[0]['Path'] : $inferred['path'];

		$manifest['network'] = array(
			'domain'    => $domain,
			'path'      => $path,
			'subdomain' => $this->subdomain( $sites, $domain, $path ),
			'main_site' => $main,
			'networks'  => max( 1, count( $networks ) ),
		);
		return $manifest;
	}

	/**
	 * The site restored onto a single site: the one chosen with --site, or the only site of a backup of picked sites.
	 *
	 * @param WpressPackage $package The archive's package.json.
	 * @param string        $choice  Site ID, address or URL ('' for the only one).
	 * @return array<string,mixed> SubsiteExtract::resolve() plan, plus picked (placeholders of picked sites) and uploads.
	 * @throws JobException When no site or an unknown site is chosen.
	 */
	public function extract( WpressPackage $package, string $choice ): array {
		$site = $this->site( $package );
		if ( '' === trim( $choice, " \t\n\r\0\x0B" ) ) {
			if ( $this->is_network() || 1 !== $this->count() ) {
				throw new JobException( sprintf( 'This backup holds %s and this is a single site: choose the site to restore with --site=<id or address>. Its sites: %s.', $this->is_network() ? 'a whole network' : $this->count() . ' sites picked from a network', SubsiteExtract::listing( $site ) ) );
			}
			$choice = (string) (int) $this->data['Sites'][0]['BlogID'];
		}
		$plan            = SubsiteExtract::resolve( $site, $choice );
		$plan['picked']  = ! $this->is_network();
		$plan['uploads'] = 'uploads';
		// Server paths as recorded (7.85 has no Absolute): the site's own uploads folder first, then the rest.
		$paths = array();
		foreach ( (array) $this->data['Sites'] as $entry ) {
			if ( (int) $entry['BlogID'] === (int) $plan['blog_id'] && is_string( $entry['WordPress']['Uploads'] ?? null ) ) {
				$paths[ $entry['WordPress']['Uploads'] ] = 'uploads_dir';
			}
		}
		$paths += array(
			$package->wordpress( 'Content' )  => 'content_dir',
			$package->wordpress( 'Absolute' ) => 'abspath',
		);
		unset( $paths[''] );
		$plan['source_paths'] = $paths;
		return $plan;
	}

	/**
	 * What the chosen site had active, as a single site: its plugins and the network-activated ones, its theme.
	 *
	 * @param int $blog Chosen site.
	 * @return array{sites:array<int,array{plugins:string[],template:string,stylesheet:string}>,sitewide:null}
	 */
	public function extract_activation( int $blog ): array {
		$all             = $this->activation();
		$site            = $all['sites'][ $blog ] ?? array(
			'plugins'    => array(),
			'template'   => '',
			'stylesheet' => '',
		);
		$site['plugins'] = array_values( array_unique( array_merge( $site['plugins'], $all['sitewide'] ) ) );
		return array(
			'sites'    => array( 1 => $site ), // The single site's options table has the bare prefix.
			'sitewide' => null,
		);
	}

	/**
	 * The Sites[] entries by BlogID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function entries(): array {
		$out = array();
		foreach ( (array) $this->data['Sites'] as $site ) {
			$out[ (int) $site['BlogID'] ] = $site;
		}
		return $out;
	}

	/**
	 * What each chosen site of a backup of picked sites had active, under its new ID; network-activated plugins join each site's.
	 *
	 * @param array<int,array{from:int,blog_id:int}> $sites Chosen sites.
	 * @return array{sites:array<int,array{plugins:string[],template:string,stylesheet:string}>,sitewide:null}
	 */
	public function import_activation( array $sites ): array {
		$all = $this->activation();
		$out = array();
		foreach ( $sites as $site ) {
			$from                          = $all['sites'][ (int) $site['from'] ] ?? array(
				'plugins'    => array(),
				'template'   => '',
				'stylesheet' => '',
			);
			$from['plugins']               = array_values( array_unique( array_merge( $from['plugins'], $all['sitewide'] ) ) );
			$out[ (int) $site['blog_id'] ] = $from;
		}
		return array(
			'sites'    => $out,
			'sitewide' => null,
		);
	}

	/**
	 * What each site had active, and the network-activated plugins, for WpressActivation.
	 *
	 * @return array{sites:array<int,array{plugins:string[],template:string,stylesheet:string}>,sitewide:string[]}
	 */
	public function activation(): array {
		$sites = array();
		foreach ( (array) $this->data['Sites'] as $site ) {
			$sites[ (int) $site['BlogID'] ] = array(
				'plugins'    => self::strings( $site['Plugins'] ?? array() ),
				'template'   => self::theme( $site['Template'] ?? null ),
				'stylesheet' => self::theme( $site['Stylesheet'] ?? null ),
			);
		}
		return array(
			'sites'    => $sites,
			'sitewide' => self::strings( $this->data['Plugins'] ?? array() ),
		);
	}

	/**
	 * What a single-site archive had active (package.json), for WpressActivation.
	 *
	 * @param WpressPackage $package Package.
	 * @param int           $blog    Site ID the backup becomes (1 on a single site).
	 * @return array{sites:array<int,array{plugins:string[],template:string,stylesheet:string}>,sitewide:null}
	 */
	public static function single_activation( WpressPackage $package, int $blog = 1 ): array {
		$data = $package->data();
		return array(
			'sites'    => array(
				$blog => array(
					'plugins'    => self::strings( $data['Plugins'] ?? array() ),
					'template'   => self::theme( $data['Template'] ?? null ),
					'stylesheet' => self::theme( $data['Stylesheet'] ?? null ),
				),
			),
			'sitewide' => null,
		);
	}

	/**
	 * ID of the main site: the one at the network's own address, else site 1
	 * (always for picked sites: the main site may not be among them), else the first.
	 *
	 * @return int
	 */
	private function main_site(): int {
		$networks = array_values( array_filter( (array) ( $this->data['Networks'] ?? array() ), 'is_array' ) );
		$ids      = array();
		foreach ( (array) $this->data['Sites'] as $site ) {
			$ids[] = (int) $site['BlogID'];
			if ( isset( $networks[0]['Domain'], $networks[0]['Path'] ) && strtolower( (string) $site['Domain'] ) === strtolower( (string) $networks[0]['Domain'] ) && (string) $site['Path'] === (string) $networks[0]['Path'] ) {
				return (int) $site['BlogID'];
			}
		}
		return in_array( 1, $ids, true ) || ! $this->is_network() ? 1 : $ids[0];
	}

	/**
	 * The network's kind, from its sites: a site in a folder of the network's
	 * own domain means subdirectories, else a site on a subdomain of it means
	 * subdomains ("www." ignored, as WordPress does). Null when the sites do
	 * not tell (only the main site, or only sites with their own domain).
	 *
	 * @param array<int,array{domain:string,path:string}> $sites  Sites.
	 * @param string                                      $domain Network domain.
	 * @param string                                      $path   Network path.
	 * @return bool|null
	 */
	private function subdomain( array $sites, string $domain, string $path ): ?bool {
		$base  = 0 === strpos( $domain, 'www.' ) ? substr( $domain, 4 ) : $domain;
		$found = null;
		foreach ( $sites as $site ) {
			if ( $site['domain'] === $domain && trim( $site['path'], '/' ) !== trim( $path, '/' ) ) {
				return false;
			}
			if ( $site['domain'] !== $domain && strlen( $site['domain'] ) > strlen( $base ) + 1 && substr( $site['domain'], -strlen( $base ) - 1 ) === '.' . $base ) {
				$found = true;
			}
		}
		return $found;
	}

	/**
	 * A theme folder name, or '' when the value is not one.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function theme( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_][A-Za-z0-9._-]*$/D', $value ) && false === strpos( $value, '..' ) ? $value : '';
	}

	/**
	 * Strings of a JSON list.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function strings( $value ): array {
		return array_values( array_filter( is_array( $value ) ? $value : array(), 'is_string' ) );
	}
}
