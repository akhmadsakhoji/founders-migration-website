<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Pull;

use Founders\Migration\Controller\PullRestController;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Model\Export\BackupOptions;
use Founders\Migration\Model\Import\NetworkMove;
use Founders\Migration\Model\Import\RestoreOptions;

defined( 'ABSPATH' ) || exit;

/**
 * Options of a pull job on the pulling (target) site.
 *
 * "restore-pull" pulls and restores in one resumable job; "pull" only
 * downloads the backup into the backups folder. The key (and a password)
 * are sealed in the job and removed when it ends.
 */
final class PullOptions {

	/**
	 * Connects to the source and checks that it can be pulled here: the
	 * same protocol, not this site itself, a network only onto a network of
	 * the same kind (see NetworkMove::incompatible()), and for an existing
	 * backup a key that may download it.
	 *
	 * @param PullClient $client        Client.
	 * @param string     $backup        Existing backup to pull ('' for a new one).
	 * @param bool       $download_only Only download: any backup fits in the backups folder.
	 * @return array<string,mixed> What the source said (GET /pull).
	 * @throws PullException When it cannot be pulled.
	 */
	public static function check( PullClient $client, string $backup = '', bool $download_only = false ): array {
		$here = function_exists( 'is_multisite' ) && is_multisite();
		$info = $client->info();
		$site = (array) ( $info['site'] ?? array() );
		if ( function_exists( 'home_url' ) && in_array( self::place( $here ? network_home_url() : home_url() ), array( self::place( $client->url() ), self::place( (string) ( $site['home_url'] ?? '' ) ) ), true ) ) {
			throw new PullException( 'That is this site. Pull from the site you want to copy, on the site that should receive the copy.', 0, 'fmw_pull_self' );
		}
		if ( ! empty( $site['multisite'] ) && ! is_array( $site['network'] ?? null ) ) {
			throw new PullException( 'The source is a multisite network with an older Founders Migration Website that cannot be pulled from: update it there to the same version as here.', 0, 'fmw_pull_protocol' );
		}
		// A download-only pull restores nothing: kinds are checked when its backup is restored later.
		if ( ! $download_only && empty( $site['multisite'] ) !== ! $here ) {
			throw new PullException(
				$here
					? 'The source is a single site and this is a multisite network: pull it with --download-only, then restore it as a site of this network with wp fmw restore <file> --site=<address>.'
					: 'The source is a multisite network and this is a single site: pull it with --download-only, then restore one of its sites here with wp fmw restore <file> --site=<id or address>.',
				0,
				'fmw_pull_multisite'
			);
		}
		if ( $here && ! $download_only ) {
			$network = (array) ( $site['network'] ?? array() );
			$problem = NetworkMove::incompatible( $network, BackupOptions::network(), (int) ( $network['sites'] ?? 1 ) );
			if ( null !== $problem ) {
				throw new PullException( $problem, 0, 'fmw_pull_multisite' );
			}
		}
		if ( '' !== $backup && empty( $info['key']['allow_existing'] ) ) {
			throw new PullException( 'This key may not download existing backups; create one that allows it on the source, or make a new backup.', 0, 'fmw_pull_existing' );
		}
		return $info;
	}

	/**
	 * A site address without scheme, "www." and trailing slash, to recognize the same site.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function place( string $url ): string {
		$url = strtolower( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', trim( $url, " \t\n\r\0\x0B" ) ) );
		return rtrim( (string) preg_replace( '/^www\./', '', $url ), '/' );
	}

	/**
	 * Job type for these settings.
	 *
	 * @param bool $download_only Only download.
	 * @return string
	 */
	public static function type( bool $download_only ): string {
		return $download_only ? 'pull' : 'restore-pull';
	}

	/**
	 * Builds job options.
	 *
	 * @param string                                                                                                                                 $url  Source site (normalized).
	 * @param string                                                                                                                                 $key  Pull key.
	 * @param array{allow_http?:bool,flags?:array<string,mixed>,password?:string,backup?:string,keep_source?:bool,restore?:array<string,mixed>|null} $args Settings; restore null for download only.
	 * @return array<string,mixed>
	 */
	public static function build( string $url, string $key, array $args ): array {
		$restore = $args['restore'] ?? null;
		$options = null === $restore ? array() : RestoreOptions::build( '', $restore );
		$flags   = array();
		foreach ( (array) ( $args['flags'] ?? array() ) as $flag => $value ) {
			if ( in_array( $flag, PullRestController::FLAGS, true ) ) {
				$flags[ $flag ] = $value;
			}
		}
		$options['pull']            = array(
			'url'         => $url,
			'allow_http'  => ! empty( $args['allow_http'] ),
			'flags'       => $flags,
			'backup'      => (string) ( $args['backup'] ?? '' ),
			'keep_source' => ! empty( $args['keep_source'] ),
		);
		$options['archive_dir']     = fmwp_backups_path();
		$options['secret_pull_key'] = Secrets::seal( $key );
		if ( '' !== (string) ( $args['password'] ?? '' ) ) {
			$options['secret_password'] = Secrets::seal( (string) $args['password'] );
		}
		if ( null !== $restore && ! empty( $restore['skip-space-check'] ) ) {
			$options['skip_space_check'] = true;
		}
		return $options;
	}
}
