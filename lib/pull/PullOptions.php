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
use Founders\Migration\Model\Import\RestoreOptions;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Options of a pull job on the pulling (target) site.
 *
 * "restore-pull" pulls and restores in one resumable job; "pull" only
 * downloads the backup into the backups folder. The key (and a password)
 * are sealed in the job and removed when it ends.
 */
final class PullOptions {

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
