<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Cli;

defined( 'ABSPATH' ) || exit;

use Founders\Migration\Job\Deadline;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\Runner;
use Founders\Migration\Model\Import\SubsiteImport;
use WP_CLI;

/**
 * Runs a job in the foreground for the WP-CLI commands.
 */
final class JobRunner {

	/**
	 * Runs a job in the foreground with progress output and ai1wm-style exit codes.
	 *
	 * @param Job  $job         Job.
	 * @param bool $no_progress Hide the progress bar.
	 * @param bool $porcelain   Print only the result (backup file name).
	 * @return void
	 */
	public static function run( Job $job, bool $no_progress, bool $porcelain = false ): void {
		$bar    = $no_progress ? null : new ProgressBar();
		$runner = new Runner(
			Jobs::store(),
			Jobs::registry(),
			static function ( Job $job ) use ( $bar ) {
				if ( null !== $bar && '' !== $job->phase ) {
					$bar->update( $job->phase, $job->bytes_done, $job->bytes_total );
				}
			}
		);

		if ( ! $porcelain ) {
			WP_CLI::log( sprintf( 'FMW %s · job %s', FMWP_VERSION, $job->id ) );
		}
		try {
			$runner->run( $job, Deadline::unlimited(), true );
		} catch ( JobException $e ) {
			WP_CLI::error( $e->getMessage() );
		} finally {
			if ( null !== $bar ) {
				$bar->finish();
			}
		}

		if ( Job::STATUS_COMPLETED === $job->status ) {
			if ( Jobs::changes_site( $job->type ) ) {
				if ( ! empty( $job->options['reset_network'] ) && is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
					switch_to_blog( get_main_site_id() ); // Only the main site is left: the site of --url is gone.
				}
				Jobs::store()->purge_work_files( $job->id );
				wp_cache_flush();
				delete_option( 'rewrite_rules' );
				if ( 'reset' === $job->type ) {
					WP_CLI::success( sprintf( 'Reset complete%s: %s.', (int) ( $job->options['reset_site'] ?? 0 ) > 0 ? ' (site ' . (int) $job->options['reset_site'] . ')' : ( empty( $job->options['reset_network'] ) ? '' : ' (the whole network)' ), implode( ', ', (array) ( $job->options['reset'] ?? array() ) ) ) );
					return;
				}
				if ( ! empty( $job->data['import']['picked'] ) ) {
					$list = array();
					foreach ( SubsiteImport::sites( $job->data['import'] ) as $site ) {
						$list[] = sprintf( '%s (site %d, was %d)', SubsiteImport::address( $site, (array) ( $job->data['network_target'] ?? $job->options['target'] ) )['home_url'], (int) $site['blog_id'], (int) $site['from'] );
					}
					WP_CLI::success( sprintf( 'Restore complete. These are sites of the network now: %s. Their users log in with the network\'s accounts.', implode( ', ', $list ) ) );
					return;
				}
				if ( is_array( $job->data['import'] ?? null ) ) {
					WP_CLI::success( sprintf( 'Restore complete. %s is site %d of the network now; its users log in with the network\'s accounts.', (string) ( $job->options['target']['home_url'] ?? '' ), (int) $job->data['import']['blog_id'] ) );
					return;
				}
				WP_CLI::success( sprintf( 'Restore complete. %s now runs the restored %s; log in with its accounts.', home_url(), is_multisite() ? 'network' : 'site' ) );
				return;
			}
			if ( 'pull' === $job->type ) {
				Jobs::store()->purge_work_files( $job->id );
				WP_CLI::success( sprintf( 'Pulled to %s (%s). Restore it with `wp fmw restore %s`.', $job->data['archive']['path'], ProgressBar::bytes( (int) $job->data['archive']['bytes'] ), $job->data['archive']['name'] ) );
				return;
			}
			if ( 'download' === $job->type ) {
				Jobs::store()->purge_work_files( $job->id );
				WP_CLI::success( sprintf( 'Downloaded to %s (%s). Restore it with `wp fmw restore %s`.', $job->data['archive']['path'], ProgressBar::bytes( (int) $job->data['archive']['bytes'] ), $job->data['archive']['name'] ) );
				return;
			}
			if ( 'upload' === $job->type ) {
				Jobs::store()->purge_work_files( $job->id );
				WP_CLI::success( sprintf( 'Uploaded to "%s" as %s.', $job->data['remote']['name'], ( $job->data['remote']['label'] ?? $job->data['remote']['key'] ) ) );
				return;
			}
			if ( isset( $job->data['archive']['path'] ) ) {
				Jobs::store()->purge_work_files( $job->id );
				if ( $porcelain ) {
					WP_CLI::line( (string) $job->data['archive']['name'] );
					return;
				}
				$where = isset( $job->data['remote']['key'] ) ? sprintf( ', uploaded to "%s" as %s', $job->data['remote']['name'], ( $job->data['remote']['label'] ?? $job->data['remote']['key'] ) ) : '';
				if ( ! empty( $job->data['archive']['deleted_local'] ) ) {
					WP_CLI::success( sprintf( 'Backup %s (%s) uploaded to "%s" as %s; the copy on this server was deleted.', $job->data['archive']['name'], ProgressBar::bytes( (int) $job->data['archive']['bytes'] ), $job->data['remote']['name'], ( $job->data['remote']['label'] ?? $job->data['remote']['key'] ) ) );
					return;
				}
				WP_CLI::success( sprintf( 'Backup created: %s (%s)%s.', $job->data['archive']['path'], ProgressBar::bytes( (int) $job->data['archive']['bytes'] ), $where ) );
				return;
			}
			WP_CLI::success( sprintf( 'Job %s completed.', $job->id ) );
			return;
		}
		if ( Job::STATUS_CANCELLED === $job->status ) {
			WP_CLI::warning( sprintf( 'Job %s was cancelled.', $job->id ) );
			WP_CLI::halt( 1 );
		}
		if ( Job::STATUS_FAILED === $job->status ) {
			WP_CLI::error( sprintf( 'Job %s failed: %s Fix the cause, then run `wp fmw resume %s`.', $job->id, rtrim( (string) $job->error, '.' ) . '.', $job->id ) );
		}
		WP_CLI::warning( sprintf( 'Job %s stopped. Continue with `wp fmw resume %s`.', $job->id, $job->id ) );
		WP_CLI::halt( Command::EXIT_RESUMABLE );
	}
}
