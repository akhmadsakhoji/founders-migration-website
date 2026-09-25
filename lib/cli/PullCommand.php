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

use Founders\Migration\Controller\PullRestController;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Pull\PullClient;
use Founders\Migration\Pull\PullException;
use Founders\Migration\Pull\PullOptions;
use Founders\Migration\Storage\Paths;
use WP_CLI;

/**
 * Copies another site onto this one over HTTPS, without downloading and uploading by hand.
 */
final class PullCommand {

	/**
	 * Pulls a site: a backup is made on the source, downloaded, and restored here.
	 *
	 * Create a key on the source site first (`wp fmw pull-key create`, or on
	 * its Pull screen). Everything runs as one resumable job: if it stops,
	 * `wp fmw resume <job_id>` continues where it was, and the temporary
	 * backup on the source is deleted after the download. The restore works
	 * like `wp fmw restore`: URLs and paths are replaced for this site, and
	 * the database is switched in with one atomic rename at the end.
	 *
	 * ## OPTIONS
	 *
	 * [<url>]
	 * : Address of the source site, for example https://old.example.com.
	 *
	 * [--job=<id>]
	 * : Continue an interrupted pull with a new key (when the old one expired or was revoked). With a new key the source's job starts again, but a download continues when the key was created with --allow-existing.
	 *
	 * [--key=<key>]
	 * : Pull key from the source site (fmwpk_...). Asked for without echo when missing; FMW_PULL_KEY works too.
	 *
	 * [--backup=<name>]
	 * : Pull this existing backup of the source instead of making a new one (the key needs --allow-existing).
	 *
	 * [--password[=<password>]]
	 * : Encrypt the backup while it waits on the source (asked for without echo when no value is given). Also the password of an encrypted --backup.
	 *
	 * [--exclude-spam-comments]
	 * : Leave out spam comments.
	 *
	 * [--exclude-post-revisions]
	 * : Leave out post revisions.
	 *
	 * [--exclude-transients]
	 * : Leave out transients.
	 *
	 * [--exclude-media]
	 * : Leave out uploads.
	 *
	 * [--exclude-themes]
	 * : Leave out all themes.
	 *
	 * [--exclude-inactive-themes]
	 * : Leave out inactive themes.
	 *
	 * [--exclude-muplugins]
	 * : Leave out must-use plugins.
	 *
	 * [--exclude-plugins]
	 * : Leave out all plugins.
	 *
	 * [--exclude-inactive-plugins]
	 * : Leave out inactive plugins.
	 *
	 * [--exclude-cache]
	 * : Leave out cache folders.
	 *
	 * [--exclude-database]
	 * : Leave out the database.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma-separated table names to leave out.
	 *
	 * [--exclude-paths=<patterns>]
	 * : Comma-separated patterns relative to wp-content.
	 *
	 * [--download-only]
	 * : Only download the backup into this site's backups folder; restore it later with `wp fmw restore`.
	 *
	 * [--keep-source-backup]
	 * : Keep the backup on the source site after the download.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * [--keep-old-tables]
	 * : Keep the replaced tables as fmwold_*.
	 *
	 * [--exclude-email-replace]
	 * : Do not change e-mail addresses at the old domain.
	 *
	 * [--skip-space-check]
	 * : Start even if the free disk space looks too small.
	 *
	 * [--allow-http]
	 * : Allow a plain http:// source address (only on a trusted network; local addresses are always allowed).
	 *
	 * [--[no-]progress]
	 * : Show the progress bar (default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmw pull https://old.example.com --key=fmwpk_1a2b3c4d_...
	 *     wp fmw pull https://old.example.com --exclude-cache --exclude-post-revisions --yes
	 *     wp fmw pull https://old.example.com --download-only --keep-source-backup
	 *     wp fmw pull --job=01J9ZX0Q8W5S3T2H6D1M4K7B9C --key=fmwpk_...   # continue with a new key
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$download_only = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'download-only', false );
		if ( empty( $args ) && ! isset( $assoc_args['job'] ) ) {
			WP_CLI::error( 'Give the address of the source site, for example `wp fmw pull https://old.example.com`.' );
		}
		$key = (string) ( $assoc_args['key'] ?? getenv( 'FMW_PULL_KEY' ) );
		if ( '' === $key ) {
			$key = self::ask( 'Pull key from the source site' );
		}
		if ( isset( $assoc_args['job'] ) ) {
			$this->continue_with_key( (string) $assoc_args['job'], $key, $assoc_args );
			return;
		}
		$password = '';
		if ( isset( $assoc_args['password'] ) ) {
			if ( '' !== $assoc_args['password'] ) {
				$password = $assoc_args['password'];
			} else {
				$password = self::ask( 'Password for the backup' );
				if ( ! isset( $assoc_args['backup'] ) && self::ask( 'Repeat the password' ) !== $password ) {
					WP_CLI::error( 'The passwords do not match.' );
				}
			}
			if ( ! isset( $assoc_args['backup'] ) && strlen( $password ) < 8 ) {
				WP_CLI::error( 'The backup password must be at least 8 characters long.' );
			}
		}

		try {
			$client = new PullClient( $args[0], $key, (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'allow-http', false ) );
			$info   = $client->info();
		} catch ( PullException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$site = (array) ( $info['site'] ?? array() );
		if ( untrailingslashit( (string) ( $site['home_url'] ?? '' ) ) === untrailingslashit( home_url() ) ) {
			WP_CLI::error( 'That is this site. Run `wp fmw pull` on the site that should receive the copy.' );
		}
		if ( ! empty( $site['multisite'] ) ) {
			WP_CLI::error( 'The source is a multisite network; pulling networks arrives in a later version.' );
		}
		if ( isset( $assoc_args['backup'] ) && empty( $info['key']['allow_existing'] ) ) {
			WP_CLI::error( 'This key may not download existing backups; create one with --allow-existing on the source, or leave out --backup.' );
		}
		WP_CLI::log(
			sprintf(
				'Source: %s ("%s", WordPress %s, FMW %s). Key valid until %s.',
				(string) ( $site['home_url'] ?? '?' ),
				(string) ( $site['name'] ?? '' ),
				(string) ( $site['wp_version'] ?? '?' ),
				(string) ( $info['fmw'] ?? '?' ),
				wp_date( 'Y-m-d H:i', (int) ( $info['key']['expires_at'] ?? 0 ) )
			)
		);
		if ( ! $download_only ) {
			WP_CLI::confirm( sprintf( 'Copy %s onto %s? This replaces this site\'s files and database.', (string) ( $site['home_url'] ?? '?' ), home_url() ), $assoc_args );
		}

		$flags = array();
		foreach ( PullRestController::FLAGS as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$flags[ $flag ] = $assoc_args[ $flag ];
			}
		}
		Paths::ensure_all();
		$options = PullOptions::build(
			$client->url(),
			$key,
			array(
				'allow_http'  => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'allow-http', false ),
				'flags'       => $flags,
				'password'    => $password,
				'backup'      => (string) ( $assoc_args['backup'] ?? '' ),
				'keep_source' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-source-backup', false ),
				'restore'     => $download_only ? null : $assoc_args,
			)
		);
		$job     = Jobs::store()->create( PullOptions::type( $download_only ), $options );
		JobRunner::run( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
	}

	/**
	 * Gives an unfinished pull job a new key, then continues it.
	 *
	 * @param string               $id         Job id.
	 * @param string               $key        New key.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	private function continue_with_key( string $id, string $key, array $assoc_args ): void {
		$store = Jobs::store();
		try {
			$job = $store->load( $id );
		} catch ( \Throwable $e ) {
			WP_CLI::error( sprintf( 'No job %s; see `wp fmw jobs`.', $id ) );
			return;
		}
		if ( ! in_array( $job->type, array( 'pull', 'restore-pull' ), true ) || $job->is_finished() ) {
			WP_CLI::error( sprintf( 'Job %s is not an unfinished pull.', $id ) );
		}
		try {
			$client = new PullClient( (string) ( $job->options['pull']['url'] ?? '' ), $key, ! empty( $job->options['pull']['allow_http'] ) );
			$client->info(); // Checks the new key before it is saved.
		} catch ( PullException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$job->options['secret_pull_key'] = Secrets::seal( $key );
		$store->save( $job );
		$store->log( $job->id, 'Continued with a new pull key.' );
		JobRunner::run( $job, ! WP_CLI\Utils\get_flag_value( $assoc_args, 'progress', true ) );
	}

	/**
	 * Asks for a secret without echo.
	 *
	 * @param string $label Prompt.
	 * @return string
	 */
	private static function ask( string $label ): string {
		if ( ! function_exists( 'posix_isatty' ) || ! posix_isatty( STDIN ) ) {
			WP_CLI::error( sprintf( '%s: pass it as an option (no terminal to ask on).', $label ) );
		}
		return trim( (string) \cli\prompt( $label, false, ': ', true ), " \t\n\r\0\x0B" );
	}
}
