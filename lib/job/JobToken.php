<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Job;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Job files are written with native calls.

/**
 * A secret that lets a browser run one job without a WordPress login.
 *
 * A restore replaces the users table, so the login of the browser that
 * started it usually stops being valid half-way. The token is shown once and
 * stored only as a SHA-256 hash, in its own file in the job folder (outside
 * the database, and apart from the job state so issuing a token never races
 * with a running slice). Issuing a new token replaces the old one.
 */
final class JobToken {

	const FILE = 'token';

	/**
	 * Gives a job a new token (the old one stops working).
	 *
	 * @param Job      $job   Job.
	 * @param JobStore $store Store.
	 * @return string The token (64 hex characters).
	 * @throws JobException When it cannot be stored.
	 */
	public static function issue( Job $job, JobStore $store ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$path  = $store->dir( $job->id ) . '/' . self::FILE;
		if ( false === file_put_contents( $path . '.tmp', hash( 'sha256', $token ) ) || ! rename( $path . '.tmp', $path ) ) {
			throw new JobException( 'Cannot store the job token.' );
		}
		return $token;
	}

	/**
	 * Whether a token belongs to a job.
	 *
	 * @param Job      $job   Job.
	 * @param JobStore $store Store.
	 * @param string   $token Token from the request.
	 * @return bool
	 */
	public static function matches( Job $job, JobStore $store, string $token ): bool {
		$hash = (string) @file_get_contents( $store->dir( $job->id ) . '/' . self::FILE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- No token yet.
		return 64 === strlen( $hash ) && 64 === strlen( $token ) && hash_equals( $hash, hash( 'sha256', $token ) );
	}
}
