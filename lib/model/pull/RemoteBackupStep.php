<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Model\Pull;

use Founders\Migration\Job\Context;
use Founders\Migration\Job\Discardable;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\JobException;
use Founders\Migration\Job\Secrets;
use Founders\Migration\Job\Step;
use Founders\Migration\Pull\PullClient;
use Founders\Migration\Pull\PullException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * Makes a backup on the source site and drives it to the end, slice by
 * slice, from this site (the source needs no WP-Cron or loopback for it).
 * With an existing backup chosen (--backup) there is nothing to do.
 *
 * Reads job options: pull (url, allow_http, flags, backup), secret_pull_key,
 * secret_password. Sets job data: pull (job, name, made).
 */
final class RemoteBackupStep implements Step, Discardable {

	const MAX_BUSY = 30; // Answers "busy" in a row before giving up (about five minutes).

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Remote backup';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job     $job     Job.
	 * @param Context $context Context.
	 * @throws JobException When the source refuses or its backup fails.
	 */
	public function run( Job $job, Context $context ): bool {
		$pull  = (array) ( $job->options['pull'] ?? array() );
		$state = (array) ( $job->data['pull'] ?? array() );
		if ( '' !== (string) ( $pull['backup'] ?? '' ) ) {
			$job->data['pull'] = array(
				'name' => (string) $pull['backup'],
				'made' => false,
			);
			$context->log( sprintf( 'Using the existing backup %s of %s.', $pull['backup'], $pull['url'] ) );
			return true;
		}
		if ( '' !== (string) ( $state['name'] ?? '' ) ) {
			return true;
		}
		$client = self::client( $job );
		try {
			while ( '' === (string) ( $state['job'] ?? '' ) ) {
				try {
					$password = (string) Secrets::open( $job->options['secret_password'] ?? null );
					$remote   = $client->start_backup( (array) ( $pull['flags'] ?? array() ), $password );
				} catch ( PullException $e ) {
					$state['busy'] = (int) ( $state['busy'] ?? 0 ) + 1;
					if ( 409 !== $e->status || $state['busy'] > self::MAX_BUSY ) {
						throw new JobException( 'Pull: ' . $e->getMessage() );
					}
					if ( 1 === $state['busy'] ) {
						$context->log( 'The source site is busy with another backup or restore; waiting for it to finish.' );
					}
					$job->data['pull'] = $state;
					sleep( 10 );
					if ( ! $context->should_continue() ) {
						return false;
					}
					continue;
				}
				$job->data['pull'] = array(
					'job'  => (string) $remote['id'],
					'made' => true,
					'busy' => 0,
				);
				$context->log( sprintf( 'Started a backup on %s (job %s).', $client->url(), $remote['id'] ) );
				return false; // Checkpoint the remote job id before anything else.
			}
			do {
				try {
					$remote        = $client->run( (string) $state['job'] );
					$state['busy'] = 0;
				} catch ( PullException $e ) {
					$state['busy'] = (int) ( $state['busy'] ?? 0 ) + 1;
					if ( 404 === $e->status ) {
						unset( $job->data['pull'] ); // The source forgot the job (or the key changed): a resume starts again.
					}
					if ( 409 !== $e->status || $state['busy'] > self::MAX_BUSY ) {
						throw new JobException( 'Pull: ' . $e->getMessage() );
					}
					$job->data['pull'] = $state;
					sleep( 10 ); // Another job runs on the source; it usually ends soon.
					continue;
				}
				$job->bytes_total  = (int) ( $remote['bytes_total'] ?? 0 );
				$job->bytes_done   = (int) ( $remote['bytes_done'] ?? 0 );
				$job->data['pull'] = $state;
				$context->report_progress();
				switch ( (string) ( $remote['status'] ?? '' ) ) {
					case Job::STATUS_COMPLETED:
						$state['name']     = (string) ( $remote['backup']['name'] ?? '' );
						$job->data['pull'] = $state;
						if ( '' === $state['name'] ) {
							throw new JobException( 'The source site finished its backup but did not name it.' );
						}
						$context->log( sprintf( 'The source site made %s (%d bytes).', $state['name'], (int) ( $remote['backup']['size'] ?? 0 ) ) );
						return true;
					case Job::STATUS_FAILED:
						throw new JobException( sprintf( 'The backup on the source site failed: %s', (string) ( $remote['error'] ?? 'no reason given' ) ) );
					case Job::STATUS_CANCELLED:
						unset( $job->data['pull'] ); // A resume starts a new backup.
						throw new JobException( 'The backup on the source site was cancelled there. Resume to start a new one.' );
				}
			} while ( $context->should_continue() );
		} catch ( PullException $e ) {
			throw new JobException( 'Pull: ' . $e->getMessage() );
		}
		return false;
	}

	/**
	 * Cancels the source's backup and deletes what it made (best effort).
	 *
	 * @param Job $job Cancelled job.
	 * @return void
	 */
	public function discard( Job $job ): void {
		$state = (array) ( $job->data['pull'] ?? array() );
		if ( empty( $state['made'] ) ) {
			return;
		}
		try {
			$client = self::client( $job );
			if ( '' !== (string) ( $state['job'] ?? '' ) && '' === (string) ( $state['name'] ?? '' ) ) {
				$remote = $client->cancel( (string) $state['job'] );
				if ( isset( $remote['backup']['name'] ) ) {
					$state['name'] = (string) $remote['backup']['name'];
				}
			}
			if ( '' !== (string) ( $state['name'] ?? '' ) && empty( $state['deleted'] ) && empty( $job->options['pull']['keep_source'] ) ) {
				$client->delete( (string) $state['name'] );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The source may be unreachable; its key expires, and the backup can be deleted there by hand.
		}
	}

	/**
	 * Client for the job's source site.
	 *
	 * @param Job $job Job.
	 * @return PullClient
	 * @throws JobException When the saved key cannot be read.
	 */
	public static function client( Job $job ): PullClient {
		$pull = (array) ( $job->options['pull'] ?? array() );
		$key  = Secrets::open( $job->options['secret_pull_key'] ?? null );
		if ( null === $key ) {
			throw new JobException( 'The pull key of this job cannot be read any more (the job ended, or the site\'s salts changed). Start the pull again.' );
		}
		try {
			return new PullClient( (string) ( $pull['url'] ?? '' ), $key, ! empty( $pull['allow_http'] ) );
		} catch ( PullException $e ) {
			throw new JobException( $e->getMessage() );
		}
	}
}
