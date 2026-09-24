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

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The store uses native file calls and flock().

/**
 * Backup schedules, kept in one JSON file in the storage folder.
 *
 * The file lives next to the job state, not in the database: schedules are
 * part of this server's setup, so restoring a backup, migrating another
 * site in or resetting the database never removes, replaces or copies them.
 * Changes are serialised with an exclusive lock and written atomically
 * (temporary file, then rename), so readers never see half a file.
 */
final class ScheduleStore {

	const VERSION = 1;

	/**
	 * JSON file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Constructor.
	 *
	 * @param string $file JSON file path.
	 */
	public function __construct( string $file ) {
		$this->file = $file;
	}

	/**
	 * All schedules, oldest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		return $this->read()['schedules'];
	}

	/**
	 * One schedule.
	 *
	 * @param string $id ID.
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		$all = $this->all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Store-wide values (last_tick, ...).
	 *
	 * @return array<string,mixed>
	 */
	public function meta(): array {
		return $this->read()['meta'];
	}

	/**
	 * Adds or replaces a schedule.
	 *
	 * @param array<string,mixed> $schedule Schedule with an id.
	 * @return void
	 */
	public function save( array $schedule ): void {
		$this->update(
			static function ( array &$data ) use ( $schedule ): void {
				$data['schedules'][ (string) $schedule['id'] ] = $schedule;
			}
		);
	}

	/**
	 * Deletes a schedule.
	 *
	 * @param string $id ID.
	 * @return bool Whether it existed.
	 */
	public function delete( string $id ): bool {
		return (bool) $this->update(
			static function ( array &$data ) use ( $id ): bool {
				if ( ! isset( $data['schedules'][ $id ] ) ) {
					return false;
				}
				unset( $data['schedules'][ $id ] );
				return true;
			}
		);
	}

	/**
	 * Changes one schedule's state under the lock.
	 *
	 * @param string   $id      ID.
	 * @param callable $changer Gets the schedule array, returns it changed.
	 * @return array<string,mixed>|null The changed schedule, or null when it does not exist.
	 */
	public function change( string $id, callable $changer ): ?array {
		return $this->update(
			static function ( array &$data ) use ( $id, $changer ): ?array {
				if ( ! isset( $data['schedules'][ $id ] ) ) {
					return null;
				}
				$data['schedules'][ $id ] = $changer( $data['schedules'][ $id ] );
				return $data['schedules'][ $id ];
			}
		);
	}

	/**
	 * Sets a store-wide value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function set_meta( string $key, $value ): void {
		$this->update(
			static function ( array &$data ) use ( $key, $value ): void {
				$data['meta'][ $key ] = $value;
			}
		);
	}

	/**
	 * Read-modify-write under an exclusive lock.
	 *
	 * @param callable $changer Gets the data by reference; its return value is returned.
	 * @return mixed
	 * @throws \RuntimeException When the file cannot be locked or written.
	 */
	public function update( callable $changer ) {
		$lock = fopen( $this->file . '.lock', 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot lock the schedules file.' );
		}
		try {
			$data   = $this->read( true );
			$result = $changer( $data );
			$json   = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$tmp    = $this->file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
			if ( false === $json || false === file_put_contents( $tmp, $json . "\n" ) || ! rename( $tmp, $this->file ) ) {
				if ( is_file( $tmp ) ) {
					unlink( $tmp );
				}
				throw new \RuntimeException( 'Cannot write the schedules file.' );
			}
			return $result;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Current contents.
	 *
	 * @param bool $strict Refuse a damaged file (before writing), instead of reading it as empty.
	 * @return array{version:int,schedules:array<string,array<string,mixed>>,meta:array<string,mixed>}
	 * @throws \RuntimeException In strict mode, when the file is damaged.
	 */
	private function read( bool $strict = false ): array {
		$raw  = is_file( $this->file ) ? (string) file_get_contents( $this->file ) : '';
		$data = '' === $raw ? array() : json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			if ( $strict ) {
				throw new \RuntimeException( sprintf( 'The schedules file %s is damaged; fix or delete it.', $this->file ) );
			}
			$data = array();
		}
		return array(
			'version'   => self::VERSION,
			'schedules' => isset( $data['schedules'] ) && is_array( $data['schedules'] ) ? $data['schedules'] : array(),
			'meta'      => isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array(),
		);
	}
}
