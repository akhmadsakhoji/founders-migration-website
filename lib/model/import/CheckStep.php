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
use Founders\Migration\Archive\FmwArchive;
use Founders\Migration\Archive\PasswordException;
use Founders\Migration\Job\Context;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\Step;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Reads the archive's manifest and refuses restores that cannot succeed, before anything changes.
 *
 * Reads job options: archive, target { home_url, site_url, abspath,
 * content_dir, table_prefix, multisite, network }, domain_map, skip_space_check.
 * Writes job data: manifest (site, options, totals, parts), network (where
 * each site of a multisite network goes, see NetworkMove).
 */
final class CheckStep implements Step {

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Check';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the restore cannot go ahead.
	 */
	public function run( Job $job, Context $context ): bool {
		$target = (array) ( $job->options['target'] ?? array() );
		try {
			$archive  = new FmwArchive( (string) ( $job->options['archive'] ?? '' ) );
			$header   = $archive->header();
			$manifest = $archive->manifest( Secrets::open( $job->options['secret_password'] ?? null ) );
		} catch ( PasswordException $e ) {
			throw new JobException( $e->given ? $e->getMessage() : 'This backup is encrypted: start the restore again with its password.' );
		} catch ( ArchiveException $e ) {
			throw new JobException( $e->getMessage() );
		}
		$encrypted = ! empty( $header['encrypted'] );

		$site    = (array) ( $manifest['site'] ?? array() );
		$network = null;
		if ( ! empty( $site['multisite'] ) || ! empty( $target['multisite'] ) ) {
			if ( empty( $site['multisite'] ) !== empty( $target['multisite'] ) ) {
				throw new JobException( 'Restoring between a single site and a multisite network arrives later in phase 3.' );
			}
			$network = NetworkMove::plan( $site, $target, (array) ( $job->options['domain_map'] ?? array() ) );
		} elseif ( ! empty( $job->options['domain_map'] ) ) {
			throw new JobException( '--map is for restoring a multisite network onto a network.' );
		}

		$raw_files = 0;
		$raw_db    = 0;
		$largest   = 0;
		$has_db    = false;
		foreach ( (array) $manifest['parts'] as $part ) {
			if ( 1 !== preg_match( '#^(database|files|root-files)/[A-Za-z0-9._$-]+$#', (string) ( $part['path'] ?? '' ) ) || false !== strpos( (string) $part['path'], '..' ) ) {
				throw new JobException( sprintf( 'Unsafe part name "%s" in the manifest; the archive is refused.', preg_replace( '/[^\x20-\x7E]/', '?', (string) ( $part['path'] ?? '' ) ) ) );
			}
			$largest = max( $largest, (int) $part['bytes'] );
			if ( 'database' === $part['type'] ) {
				$raw_db += (int) $part['bytes_raw'];
				$has_db  = true;
			} elseif ( 'files' === $part['type'] ) {
				$raw_files += (int) $part['bytes_raw'];
			} else {
				throw new JobException( sprintf( 'Unknown part type "%s"; update the plugin.', $part['type'] ) );
			}
			if ( ! in_array( $part['compression'], array( 'gzip', 'none' ), true ) ) {
				throw new JobException( sprintf( 'Unknown compression "%s"; update the plugin.', $part['compression'] ) );
			}
			$sealed = '.enc' === substr( (string) $part['path'], -4 );
			if ( $sealed xor $encrypted ) {
				throw new JobException( sprintf( 'Part %s does not match the archive\'s encryption setting.', $part['path'] ) );
			}
		}

		// Staging one part (twice when it is decrypted) + the extracted files + the database twice (live and imported), plus 10%.
		$needed = (int) ( ( ( $encrypted ? 2 : 1 ) * $largest + $raw_files + 2 * $raw_db ) * 1.1 );
		$free   = @disk_free_space( (string) ( $target['content_dir'] ?? '.' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not available on every host; treated as unknown.
		if ( false !== $free && $free < $needed && empty( $job->options['skip_space_check'] ) ) {
			throw new JobException( sprintf( 'Not enough disk space: the restore needs about %d MB, %d MB are free. Free up space or pass --skip-space-check.', (int) ( $needed / 1048576 ), (int) ( $free / 1048576 ) ) );
		}

		if ( $has_db ) {
			$restore = new RestoreDatabase();
			$restore->drop( $restore->tables( RestoreDatabase::TMP ) ); // Leftovers of an earlier, abandoned restore.
			$restore->create_progress();
			$restore->db()->close();
		}

		$job->data['manifest']  = array(
			'site'    => $site,
			'options' => (array) ( $manifest['options'] ?? array() ),
			'totals'  => (array) ( $manifest['totals'] ?? array() ),
			'parts'   => array_values( (array) $manifest['parts'] ),
		);
		$job->data['network']   = $network;
		$job->data['has_db']    = $has_db;
		$job->data['encrypted'] = $encrypted;
		if ( $encrypted ) {
			$job->data['kdf_iterations'] = FmwArchive::iterations( $header );
		}

		$context->log(
			sprintf(
				'Restoring %s (created %s by %s) onto %s: %d parts, %d files, %d tables.',
				(string) ( $site['home_url'] ?? '?' ),
				(string) ( $manifest['created_at'] ?? '?' ),
				(string) ( $manifest['generator'] ?? '?' ),
				(string) ( $target['home_url'] ?? '?' ),
				count( (array) $manifest['parts'] ),
				(int) ( $manifest['totals']['files'] ?? 0 ),
				(int) ( $manifest['totals']['tables'] ?? 0 )
			)
		);
		if ( null !== $network ) {
			self::log_network( $network, $context );
		}
		return true;
	}

	/**
	 * Logs where the network's sites go.
	 *
	 * @param array<string,mixed> $network Plan from NetworkMove::plan().
	 * @param Context             $context Context.
	 * @return void
	 */
	public static function log_network( array $network, Context $context ): void {
		if ( empty( $network['moved'] ) ) {
			$context->log( sprintf( 'Network with %d sites, same address.', count( (array) $network['sites'] ) ) );
			return;
		}
		foreach ( (array) $network['sites'] as $blog ) {
			$from = $blog['from']['domain'] . $blog['from']['path'];
			$to   = $blog['to']['domain'] . $blog['to']['path'];
			$context->log( sprintf( 'Site %d: %s', $blog['blog_id'], $from === $to ? $from . ' (own domain, kept; change it with --map)' : $from . ' -> ' . $to ) );
		}
	}
}
