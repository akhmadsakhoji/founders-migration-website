<?php
/**
 * Founders Migration Website
 *
 * @package   Founders\Migration
 * @copyright Copyright (C) 2026 PT Founder Media Partner
 * @license   GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Founders\Migration\Remote;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for WP-CLI, logs and JSON; the admin screens insert them as text, never as HTML.

/**
 * S3-compatible storages: small files in one PUT, larger ones as multipart
 * uploads (parts of at least 5 MiB, at most 10,000 of them).
 */
final class S3Driver implements Driver {

	const SINGLE_MAX = 8388608; // 8 MiB: one PUT.

	/**
	 * Storage settings.
	 *
	 * @var array<string,mixed>
	 */
	private $storage;

	/**
	 * Client.
	 *
	 * @var S3Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @param S3Client            $client  Client.
	 */
	public function __construct( array $storage, S3Client $client ) {
		$this->storage = $storage;
		$this->client  = $client;
	}

	/**
	 * The underlying client.
	 *
	 * @return S3Client
	 */
	public function client(): S3Client {
		return $this->client;
	}

	/**
	 * Writes, reads, lists and deletes a small test file.
	 *
	 * @return string What was checked.
	 * @throws RemoteException When a check fails.
	 */
	public function test(): string {
		$key  = StorageOptions::key( $this->storage, '.fmw-connection-test-' . bin2hex( random_bytes( 4 ) ) );
		$body = 'Founders Migration Website connection test ' . gmdate( 'c' );
		$this->client->put_string( $key, $body, $this->headers() );
		try {
			if ( $this->client->get_string( $key ) !== $body ) {
				throw new RemoteException( 'The test file came back different from what was written.' );
			}
			$this->client->list( $this->prefix(), 1 );
		} finally {
			$this->client->delete( $key );
		}
		return 'write, read, list and delete work';
	}

	/**
	 * Backups (.fmw, .wpress) in the storage folder, newest first.
	 *
	 * @return array<int,array{name:string,key:string,size:int,mtime:int}>
	 */
	public function backups(): array {
		$prefix = $this->prefix();
		$items  = array();
		foreach ( $this->client->list( $prefix ) as $object ) {
			$name = substr( $object['key'], strlen( $prefix ) );
			if ( '' !== $name && Storages::is_backup_name( $name ) ) {
				$items[] = $object + array( 'name' => $name );
			}
		}
		return Storages::newest_first( $items );
	}

	/**
	 * A backup by file name, or null.
	 *
	 * @param string $name File name.
	 * @return array{name:string,key:string,size:int,mtime:int,etag:string}|null
	 */
	public function find( string $name ): ?array {
		$key    = StorageOptions::key( $this->storage, $name );
		$object = $this->client->head( $key );
		return null === $object ? null : array(
			'name'  => basename( $name ),
			'key'   => $key,
			'size'  => $object['size'],
			'mtime' => $object['mtime'],
			'etag'  => $object['etag'],
		);
	}

	/**
	 * Starts an upload.
	 *
	 * @param string $name File name.
	 * @param int    $size Bytes.
	 * @return array<string,mixed> State, with offset 0.
	 */
	public function upload_open( string $name, int $size ): array {
		$key = StorageOptions::key( $this->storage, $name );
		if ( $size <= self::SINGLE_MAX ) {
			return array(
				'key'    => $key,
				'id'     => '',
				'offset' => 0,
			);
		}
		return array(
			'key'    => $key,
			'id'     => $this->client->create_multipart( $key, $this->headers() ),
			'parts'  => array(),
			'offset' => 0,
		);
	}

	/**
	 * Brings the state in line with what the storage really has (after a crash).
	 *
	 * @param array<string,mixed> $state State.
	 * @param int                 $size  Bytes.
	 * @return array<string,mixed>
	 */
	public function upload_sync( array $state, int $size ): array {
		return $state; // Parts sent after the last checkpoint are simply sent again (same number, same bytes).
	}

	/**
	 * Length of the next chunk: $wanted adjusted to the storage's rules.
	 *
	 * @param array<string,mixed> $state  State.
	 * @param int                 $size   Bytes in total.
	 * @param int                 $wanted Bytes the caller would like to send.
	 * @return int
	 */
	public function chunk_length( array $state, int $size, int $wanted ): int {
		$left = $size - (int) $state['offset'];
		if ( '' === (string) $state['id'] ) {
			return $left; // One PUT.
		}
		$number = count( (array) $state['parts'] ) + 1;
		$needed = (int) ( ceil( $left / max( 1, S3Client::MAX_PARTS - $number + 1 ) / 1048576 ) * 1048576 );
		$length = max( S3Client::MIN_PART, $needed, (int) ( floor( $wanted / 1048576 ) * 1048576 ) );
		return min( $left, $length );
	}

	/**
	 * Sends bytes offset..offset+length-1.
	 *
	 * @param array<string,mixed> $state  State.
	 * @param string              $path   File.
	 * @param int                 $length Bytes.
	 * @param int                 $size   Bytes in total.
	 * @return array<string,mixed> State with the new offset.
	 * @throws RemoteException UploadExpired when the storage forgot the multipart upload.
	 */
	public function upload_chunk( array $state, string $path, int $length, int $size ): array {
		if ( '' === (string) $state['id'] ) {
			$this->client->put_file( (string) $state['key'], $path, 0, $size, $this->headers() );
			$state['offset'] = $size;
			return $state;
		}
		$number = count( (array) $state['parts'] ) + 1;
		try {
			$etag = $this->client->upload_part( (string) $state['key'], (string) $state['id'], $number, $path, (int) $state['offset'], $length );
		} catch ( RemoteException $e ) {
			if ( 'NoSuchUpload' === $e->error_code ) {
				throw new RemoteException( 'The storage no longer knows the unfinished upload.', 404, 'UploadExpired' );
			}
			throw $e;
		}
		$state['parts'][ (string) $number ] = $etag;
		$state['offset']                    = (int) $state['offset'] + $length;
		return $state;
	}

	/**
	 * Finishes the upload (idempotent) and checks the size.
	 *
	 * @param array<string,mixed> $state State.
	 * @param int                 $size  Bytes.
	 * @return array{key:string,size:int}
	 * @throws RemoteException When the object is not complete.
	 */
	public function upload_close( array $state, int $size ): array {
		$key = (string) $state['key'];
		if ( 0 === $size && '' === (string) $state['id'] ) {
			$this->client->put_string( $key, '', $this->headers() ); // No chunk was sent for an empty file.
		}
		if ( '' !== (string) $state['id'] ) {
			$parts = array();
			foreach ( (array) $state['parts'] as $number => $etag ) {
				$parts[ (int) $number ] = (string) $etag;
			}
			try {
				$this->client->complete_multipart( $key, (string) $state['id'], $parts );
			} catch ( RemoteException $e ) {
				// Completed already (a retried request, or a crash right after it): fine when the object is whole.
				$done = 'NoSuchUpload' === $e->error_code ? $this->client->head( $key ) : null;
				if ( null === $done || $done['size'] !== $size ) {
					throw $e;
				}
			}
		}
		$object = $this->client->head( $key );
		if ( null === $object || $object['size'] !== $size ) {
			throw new RemoteException( sprintf( 'The uploaded copy has %s bytes instead of %d.', null === $object ? 'no' : (string) $object['size'], $size ) );
		}
		return array(
			'key'  => $key,
			'size' => $size,
		);
	}

	/**
	 * Abandons an unfinished upload (best effort).
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	public function upload_abort( array $state ): void {
		if ( '' !== (string) ( $state['id'] ?? '' ) ) {
			$this->client->abort_multipart( (string) $state['key'], (string) $state['id'] );
		}
	}

	/**
	 * Downloads bytes $from..$to of a backup into an open file.
	 *
	 * @param string   $key  Key.
	 * @param int      $from First byte.
	 * @param int      $to   Last byte.
	 * @param resource $sink Writable handle at the right position.
	 * @param string   $etag Version seen when the download started ('' for any).
	 * @return int Bytes written.
	 */
	public function download_range( string $key, int $from, int $to, $sink, string $etag ): int {
		return $this->client->get_range( $key, $from, $to, $sink, $etag );
	}

	/**
	 * Deletes a backup (no error when it is already gone).
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function delete( string $key ): void {
		$this->client->delete( $key );
	}

	/**
	 * Folder prefix with a trailing slash ('' for the bucket root).
	 *
	 * @return string
	 */
	private function prefix(): string {
		return '' !== (string) $this->storage['prefix'] ? $this->storage['prefix'] . '/' : '';
	}

	/**
	 * Headers for new objects (storage class).
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return '' !== (string) ( $this->storage['storage_class'] ?? '' ) ? array( 'x-amz-storage-class' => (string) $this->storage['storage_class'] ) : array();
	}
}
