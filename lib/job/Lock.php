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

// phpcs:disable WordPress.WP.AlternativeFunctions -- flock() needs a native file handle.

/**
 * Exclusive lock for one job: flock() plus a heartbeat.
 *
 * The OS releases a flock() lock when a process dies, which covers crashes.
 * The heartbeat covers the rest: a holder that has not written a heartbeat
 * for STALE_AFTER seconds (a hung process, or a filesystem where flock()
 * is not reliable) is considered dead and the lock can be taken over.
 */
final class Lock {

	const STALE_AFTER = 300;

	/**
	 * Lock file handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Lock file path.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Use acquire().
	 *
	 * @param resource $handle Locked handle.
	 * @param string   $path   Lock file path.
	 */
	private function __construct( $handle, string $path ) {
		$this->handle = $handle;
		$this->path   = $path;
		$this->heartbeat();
	}

	/**
	 * Takes the lock, or returns null when a live holder has it.
	 *
	 * @param string $path        Lock file path.
	 * @param int    $stale_after Seconds without heartbeat after which a holder is considered dead.
	 * @return self|null
	 */
	public static function acquire( string $path, int $stale_after = self::STALE_AFTER ): ?self {
		$handle = fopen( $path, 'c+' );
		if ( false === $handle ) {
			return null;
		}
		if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
			return new self( $handle, $path );
		}
		fclose( $handle );

		$holder = self::holder( $path );
		if ( null !== $holder && time() - $holder['heartbeat'] <= $stale_after ) {
			return null;
		}

		// The holder stopped sending heartbeats: replace the lock file (new inode) and take it.
		unlink( $path );
		$handle = fopen( $path, 'c+' );
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			return null;
		}
		return new self( $handle, $path );
	}

	/**
	 * Details written by the current holder.
	 *
	 * @param string $path Lock file path.
	 * @return array{pid:int,host:string,heartbeat:int}|null
	 */
	public static function holder( string $path ): ?array {
		$raw  = is_file( $path ) ? (string) file_get_contents( $path ) : '';
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['heartbeat'] ) ) {
			return null;
		}
		return array(
			'pid'       => (int) ( $data['pid'] ?? 0 ),
			'host'      => (string) ( $data['host'] ?? '' ),
			'heartbeat' => (int) $data['heartbeat'],
		);
	}

	/**
	 * Whether a live holder has the lock right now.
	 *
	 * @param string $path        Lock file path.
	 * @param int    $stale_after Seconds without heartbeat after which a holder is considered dead.
	 * @return bool
	 */
	public static function is_held( string $path, int $stale_after = self::STALE_AFTER ): bool {
		if ( ! is_file( $path ) ) {
			return false;
		}
		$handle = fopen( $path, 'c+' );
		if ( false === $handle ) {
			return false;
		}
		$free = flock( $handle, LOCK_EX | LOCK_NB );
		if ( $free ) {
			flock( $handle, LOCK_UN );
		}
		fclose( $handle );
		if ( $free ) {
			return false;
		}
		$holder = self::holder( $path );
		return null === $holder || time() - $holder['heartbeat'] <= $stale_after;
	}

	/**
	 * Records that the holder is alive.
	 *
	 * @return void
	 */
	public function heartbeat(): void {
		if ( null === $this->handle ) {
			return;
		}
		$data = (string) json_encode(
			array(
				'pid'       => (int) getmypid(),
				'host'      => (string) gethostname(),
				'heartbeat' => time(),
			)
		);
		ftruncate( $this->handle, 0 );
		rewind( $this->handle );
		fwrite( $this->handle, $data );
		fflush( $this->handle );
	}

	/**
	 * Releases the lock and removes the lock file.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( null === $this->handle ) {
			return;
		}
		// Only remove the file if it is still ours: after a stale takeover the path belongs to the new holder.
		$mine = fstat( $this->handle );
		clearstatcache( true, $this->path );
		$current = is_file( $this->path ) ? stat( $this->path ) : false;
		if ( false !== $mine && false !== $current && $mine['ino'] === $current['ino'] && $mine['dev'] === $current['dev'] ) {
			ftruncate( $this->handle, 0 );
			unlink( $this->path );
		}
		flock( $this->handle, LOCK_UN );
		fclose( $this->handle );
		$this->handle = null;
	}
}
