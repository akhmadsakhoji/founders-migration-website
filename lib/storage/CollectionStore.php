<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

// phpcs:disable WordPress.WP.AlternativeFunctions -- The store uses native file calls and flock().

/**
 * A collection of records (schedules, cloud storages) in one JSON file in the storage folder.
 *
 * The file lives next to the job state, not in the database: these records
 * are part of this server's setup, so restoring a backup, migrating another
 * site in or resetting the database never removes, replaces or copies them.
 * Changes are serialised with an exclusive lock and written atomically
 * (temporary file, then rename), so readers never see half a file.
 */
class CollectionStore {

	const VERSION = 1;

	/**
	 * JSON file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Name of the collection in the file ("schedules", "storages").
	 *
	 * @var string
	 */
	private $collection;

	/**
	 * Constructor.
	 *
	 * @param string $file       JSON file path.
	 * @param string $collection Collection name.
	 */
	public function __construct( string $file, string $collection ) {
		$this->file       = $file;
		$this->collection = $collection;
	}

	/**
	 * All records, oldest first.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		return $this->read()['records'];
	}

	/**
	 * One record.
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
	 * Adds or replaces a record.
	 *
	 * @param array<string,mixed> $record Record with an id.
	 * @return void
	 */
	public function save( array $record ): void {
		$this->update(
			static function ( array &$data ) use ( $record ): void {
				$data['records'][ (string) $record['id'] ] = $record;
			}
		);
	}

	/**
	 * Deletes a record.
	 *
	 * @param string $id ID.
	 * @return bool Whether it existed.
	 */
	public function delete( string $id ): bool {
		return (bool) $this->update(
			static function ( array &$data ) use ( $id ): bool {
				if ( ! isset( $data['records'][ $id ] ) ) {
					return false;
				}
				unset( $data['records'][ $id ] );
				return true;
			}
		);
	}

	/**
	 * Changes one record under the lock.
	 *
	 * @param string   $id      ID.
	 * @param callable $changer Gets the record, returns it changed.
	 * @return array<string,mixed>|null The changed record, or null when it does not exist.
	 */
	public function change( string $id, callable $changer ): ?array {
		return $this->update(
			static function ( array &$data ) use ( $id, $changer ): ?array {
				if ( ! isset( $data['records'][ $id ] ) ) {
					return null;
				}
				$data['records'][ $id ] = $changer( $data['records'][ $id ] );
				return $data['records'][ $id ];
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
	 * @param callable $changer Gets the data (records, meta) by reference; its return value is returned.
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
			$json   = json_encode(
				array(
					'version'         => self::VERSION,
					$this->collection => (object) $data['records'],
					'meta'            => (object) $data['meta'],
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			);
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
	 * @return array{records:array<string,array<string,mixed>>,meta:array<string,mixed>}
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
			'records' => isset( $data[ $this->collection ] ) && is_array( $data[ $this->collection ] ) ? $data[ $this->collection ] : array(),
			'meta'    => isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array(),
		);
	}
}
