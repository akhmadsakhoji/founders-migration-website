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

use Founders\Migration\Storage\CollectionStore;

defined( 'ABSPATH' ) || exit;

/**
 * Pull keys of this (source) site, in fmw-storage/pull-keys.json.
 *
 * A key lets another site running FMW make a backup of this site and
 * download it, nothing else. It looks like fmwpk_<id>_<secret>: the id finds
 * the record, and only a SHA-256 of the 256-bit secret is stored, so the
 * file alone does not give the key away. Keys expire (default 24 hours, at
 * most 30 days), can be limited to IP addresses or ranges, and can be
 * revoked at any time. By default a key can only download backups it made
 * itself; --allow-existing also lets it download the backups already here.
 */
final class PullKeys {

	const PREFIX       = 'fmwpk_';
	const DEFAULT_TTL  = 86400;     // 24 hours.
	const MAX_TTL      = 2592000;   // 30 days.
	const MAX_KEYS     = 50;
	const FAIL_LIMIT   = 30;        // Failed attempts per address ...
	const FAIL_WINDOW  = 600;       // ... in ten minutes.
	const SECRET_BYTES = 32;

	/**
	 * Folder of the key file; null for the plugin's storage folder (tests set it).
	 *
	 * @var string|null
	 */
	public static $dir = null;

	/**
	 * Key records.
	 *
	 * @return CollectionStore
	 */
	public static function store(): CollectionStore {
		return new CollectionStore( ( self::$dir ?? fmwp_storage_path() ) . '/pull-keys.json', 'keys' );
	}

	/**
	 * Whether pulling from this site is switched off (FMWP_DISABLE_PULL).
	 *
	 * @return bool
	 */
	public static function disabled(): bool {
		return defined( 'FMWP_DISABLE_PULL' ) && FMWP_DISABLE_PULL;
	}

	/**
	 * Creates a key. The full key is returned once and never stored.
	 *
	 * @param array{name?:string,ttl?:int,ips?:string[],allow_existing?:bool,user?:int} $args Settings.
	 * @return array{key:string,record:array<string,mixed>}
	 * @throws \InvalidArgumentException On invalid settings or too many keys.
	 */
	public static function create( array $args ): array {
		$ttl = (int) ( $args['ttl'] ?? self::DEFAULT_TTL );
		if ( $ttl < 60 || $ttl > self::MAX_TTL ) {
			throw new \InvalidArgumentException( 'A pull key must be valid for between one minute and 30 days.' );
		}
		$ips = array();
		foreach ( (array) ( $args['ips'] ?? array() ) as $ip ) {
			$ip = trim( (string) $ip, " \t\n\r\0\x0B" );
			if ( '' === $ip ) {
				continue;
			}
			if ( ! self::valid_range( $ip ) ) {
				throw new \InvalidArgumentException( sprintf( '"%s" is not an IP address or range (for example 203.0.113.7, 203.0.113.0/24 or 2001:db8::/32).', $ip ) );
			}
			$ips[] = $ip;
		}
		self::purge_expired();
		if ( count( self::store()->all() ) >= self::MAX_KEYS ) {
			throw new \InvalidArgumentException( sprintf( 'This site already has %d pull keys; revoke some first.', self::MAX_KEYS ) );
		}

		$id     = bin2hex( random_bytes( 4 ) );
		$secret = self::b64url( random_bytes( self::SECRET_BYTES ) );
		$name   = trim( (string) ( $args['name'] ?? '' ), " \t\n\r\0\x0B" );
		$record = array(
			'id'             => $id,
			'name'           => '' !== $name ? substr( $name, 0, 100 ) : 'Pull key ' . $id,
			'hash'           => hash( 'sha256', $secret ),
			'created_at'     => time(),
			'created_by'     => (int) ( $args['user'] ?? 0 ),
			'expires_at'     => time() + $ttl,
			'ips'            => $ips,
			'allow_existing' => ! empty( $args['allow_existing'] ),
			'jobs'           => array(),
			'backups'        => array(),
			'last_used_at'   => 0,
			'last_ip'        => '',
			'bytes_sent'     => 0,
		);
		self::store()->save( $record );
		return array(
			'key'    => self::PREFIX . $id . '_' . $secret,
			'record' => $record,
		);
	}

	/**
	 * Checks a key from a request.
	 *
	 * @param string $key Key as sent.
	 * @param string $ip  Client address.
	 * @return array<string,mixed> The key record.
	 * @throws PullException 401 for a missing, unknown, wrong or expired key, 403 for another address, 429 for wrong keys after too many failures.
	 */
	public static function authenticate( string $key, string $ip ): array {
		$record = null;
		if ( 1 === preg_match( '/^' . self::PREFIX . '([0-9a-f]{8})_([A-Za-z0-9_-]{43})$/', $key, $m ) ) {
			$found = self::store()->get( $m[1] );
			// Compare anyway when the id is unknown, so timing does not tell which ids exist.
			$hash = null === $found ? str_repeat( '0', 64 ) : (string) $found['hash'];
			if ( hash_equals( $hash, hash( 'sha256', $m[2] ) ) && null !== $found ) {
				$record = $found;
			}
		}
		if ( null === $record ) {
			// Only failures are throttled: a valid key (256 random bits) still works from a
			// shared address someone else floods with wrong keys.
			if ( self::too_many_failures( $ip ) ) {
				throw new PullException( 'Too many wrong pull keys from this address; wait ten minutes.', 429, 'fmw_pull_throttled' );
			}
			self::record_failure( $ip );
			throw new PullException( '' === $key ? 'This request needs a pull key (X-FMW-Pull-Key header).' : 'Unknown or revoked pull key.', 401, 'fmw_pull_key_invalid' );
		}
		if ( (int) $record['expires_at'] <= time() ) {
			throw new PullException( 'This pull key has expired; create a new one on the source site.', 401, 'fmw_pull_key_expired' );
		}
		if ( ! empty( $record['ips'] ) && ! self::ip_allowed( $ip, (array) $record['ips'] ) ) {
			throw new PullException( sprintf( 'This pull key may not be used from %s.', $ip ), 403, 'fmw_pull_ip' );
		}
		return $record;
	}

	/**
	 * Records a use of the key.
	 *
	 * @param string $id    Key id.
	 * @param string $ip    Client address.
	 * @param int    $bytes Bytes sent in this request.
	 * @return void
	 */
	public static function touch( string $id, string $ip, int $bytes = 0 ): void {
		self::store()->change(
			$id,
			static function ( array $record ) use ( $ip, $bytes ): array {
				$record['last_used_at'] = time();
				$record['last_ip']      = $ip;
				$record['bytes_sent']   = (int) ( $record['bytes_sent'] ?? 0 ) + $bytes;
				return $record;
			}
		);
	}

	/**
	 * Remembers a job or backup the key created, so only it can use them.
	 *
	 * @param string $id    Key id.
	 * @param string $field "jobs" or "backups".
	 * @param string $value Job id or backup name.
	 * @return void
	 */
	public static function own( string $id, string $field, string $value ): void {
		self::store()->change(
			$id,
			static function ( array $record ) use ( $field, $value ): array {
				$list             = array_values( array_unique( array_merge( (array) ( $record[ $field ] ?? array() ), array( $value ) ) ) );
				$record[ $field ] = array_slice( $list, -100 );
				return $record;
			}
		);
	}

	/**
	 * Revokes a key.
	 *
	 * @param string $id Key id.
	 * @return bool Whether it existed.
	 */
	public static function revoke( string $id ): bool {
		$record = self::store()->get( $id );
		if ( null !== $record ) {
			self::cancel_jobs( $record );
		}
		return self::store()->delete( $id );
	}

	/**
	 * Cancels the key's unfinished backups (a revoked or expired key cannot run them any more).
	 *
	 * @param array<string,mixed> $record Key record.
	 * @return void
	 */
	private static function cancel_jobs( array $record ): void {
		if ( empty( $record['jobs'] ) || ! function_exists( 'fmwp_storage_path' ) ) {
			return;
		}
		$store  = \Founders\Migration\Job\Jobs::store();
		$runner = new \Founders\Migration\Job\Runner( $store, \Founders\Migration\Job\Jobs::registry() );
		foreach ( (array) $record['jobs'] as $job_id ) {
			try {
				$job = $store->load( (string) $job_id );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( $job->is_finished() ) {
				continue;
			}
			$store->request_cancel( $job->id );
			$lock = \Founders\Migration\Job\Lock::acquire( $store->dir( $job->id ) . '/' . \Founders\Migration\Job\JobStore::LOCK_FILE );
			if ( null !== $lock ) { // Otherwise the running slice picks the cancellation up.
				$store->log( $job->id, 'Cancelled: its pull key was revoked or expired.' );
				$runner->apply_cancel( $job );
				$lock->release();
			}
		}
	}

	/**
	 * Keys, newest first, without their hashes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		self::purge_expired();
		$keys = array();
		foreach ( self::store()->all() as $record ) {
			unset( $record['hash'] );
			$keys[] = $record;
		}
		usort(
			$keys,
			static function ( array $a, array $b ): int {
				return (int) $b['created_at'] <=> (int) $a['created_at'];
			}
		);
		return $keys;
	}

	/**
	 * Deletes keys that expired more than a day ago (a day of grace shows "expired" in lists).
	 *
	 * @return void
	 */
	public static function purge_expired(): void {
		foreach ( self::store()->all() as $record ) {
			if ( (int) ( $record['expires_at'] ?? 0 ) < time() - 86400 ) {
				self::revoke( (string) $record['id'] );
			}
		}
	}

	/**
	 * Parses a duration like 30m, 24h, 7d or a number of seconds.
	 *
	 * @param string $value Duration.
	 * @return int Seconds.
	 * @throws \InvalidArgumentException When it cannot be read.
	 */
	public static function parse_ttl( string $value ): int {
		if ( 1 !== preg_match( '/^\s*(\d+)\s*([smhd]?)\s*$/i', $value, $m ) ) {
			throw new \InvalidArgumentException( sprintf( 'Cannot read the duration "%s"; use for example 30m, 24h or 7d.', $value ) );
		}
		$units = array(
			''  => 1,
			's' => 1,
			'm' => 60,
			'h' => 3600,
			'd' => 86400,
		);
		return (int) $m[1] * $units[ strtolower( $m[2] ) ];
	}

	/**
	 * Whether an address matches one of the allowed addresses or CIDR ranges.
	 *
	 * @param string   $ip     Address.
	 * @param string[] $ranges Addresses and ranges.
	 * @return bool
	 */
	public static function ip_allowed( string $ip, array $ranges ): bool {
		$addr = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false.
		if ( false === $addr ) {
			return false;
		}
		if ( 16 === strlen( $addr ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $addr, 0, 12 ) ) {
			$addr = substr( $addr, 12 ); // An IPv4-mapped IPv6 address counts as its IPv4 address.
		}
		foreach ( $ranges as $range ) {
			$parts = explode( '/', (string) $range, 2 );
			$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validated on creation.
			if ( false === $net || strlen( $net ) !== strlen( $addr ) ) {
				continue;
			}
			$bits = isset( $parts[1] ) ? (int) $parts[1] : 8 * strlen( $net );
			$full = intdiv( $bits, 8 );
			if ( substr( $addr, 0, $full ) !== substr( $net, 0, $full ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $addr[ $full ] ) & $mask ) === ( ord( $net[ $full ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a string is an IPv4/IPv6 address or CIDR range.
	 *
	 * @param string $range Address or range.
	 * @return bool
	 */
	public static function valid_range( string $range ): bool {
		$parts = explode( '/', $range, 2 );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false.
		if ( false === $net ) {
			return false;
		}
		if ( ! isset( $parts[1] ) ) {
			return true;
		}
		return 1 === preg_match( '/^\d{1,3}$/', $parts[1] ) && (int) $parts[1] <= 8 * strlen( $net );
	}

	/**
	 * Whether an address made too many failed attempts recently.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	private static function too_many_failures( string $ip ): bool {
		return function_exists( 'get_site_transient' ) && (int) get_site_transient( self::fail_key( $ip ) ) >= self::FAIL_LIMIT; // Network-wide: every site's address takes pull requests.
	}

	/**
	 * Counts a failed attempt.
	 *
	 * @param string $ip Address.
	 * @return void
	 */
	private static function record_failure( string $ip ): void {
		if ( function_exists( 'set_site_transient' ) ) {
			set_site_transient( self::fail_key( $ip ), (int) get_site_transient( self::fail_key( $ip ) ) + 1, self::FAIL_WINDOW );
		}
	}

	/**
	 * Transient name for an address.
	 *
	 * @param string $ip Address.
	 * @return string
	 */
	private static function fail_key( string $ip ): string {
		$addr = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false.
		if ( false !== $addr && 16 === strlen( $addr ) ) {
			$ip = bin2hex( substr( $addr, 0, 8 ) ); // One counter per IPv6 /64: a host usually owns the whole /64.
		}
		return 'fmwp_pull_fail_' . md5( $ip );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key encoding, not obfuscation.
	}
}
