<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Schedule;

use Founders\Migration\Controller\RestController;
use Founders\Migration\Job\Job;
use Founders\Migration\Job\Jobs;
use Founders\Migration\Job\JobToken;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a job without a browser: a chain of non-blocking requests to this site.
 *
 * Each request runs one time slice of the job (POST /jobs/<id>/run with
 * background=1, authenticated by the job's token) and, if the job is not
 * done, sends the next request before it returns. Hosts that block such
 * loopback requests still make progress: the five-minute watchdog runs a
 * slice itself and tries again. A real cron (`wp fmw schedule run`) needs
 * none of this.
 */
final class Background {

	/**
	 * Starts (or restarts) the chain with a new job token.
	 *
	 * @param Job $job Job.
	 * @return bool Whether the request could be sent.
	 */
	public static function dispatch( Job $job ): bool {
		return self::send( $job->id, JobToken::issue( $job, Jobs::store(), JobToken::BACKGROUND ) );
	}

	/**
	 * Sends the next request of a chain.
	 *
	 * @param string $id    Job ID.
	 * @param string $token Job token.
	 * @return bool Whether the request could be sent.
	 */
	public static function send( string $id, string $token ): bool {
		$url  = add_query_arg(
			array(
				'rest_route' => '/' . RestController::NAMESPACE_V1 . '/jobs/' . $id . '/run',
				'background' => '1',
			),
			site_url( 'index.php' )
		);
		$args = array(
			'blocking'  => false,
			'timeout'   => 0.01,
			'headers'   => array( RestController::TOKEN_HEADER => $token ),
			'body'      => '',
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, as used by spawn_cron().
		);

		/**
		 * Filters the request that continues a background job (for example to add HTTP basic auth of a staging site).
		 *
		 * @param array<string,mixed> $args Arguments for wp_remote_post().
		 * @param string              $url  Request URL.
		 */
		$args     = (array) apply_filters( 'fmwp_background_request', $args, $url );
		$response = wp_remote_post( $url, $args );
		return ! is_wp_error( $response );
	}
}
