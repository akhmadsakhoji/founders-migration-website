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

use Founders\Migration\Job\Secrets;
use Founders\Migration\Storage\Backups;
use Founders\Migration\Storage\CollectionStore;
use Founders\Migration\Storage\Uploads;

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Cloud storages of this site (fmw-storage/storages.json) and what can be done with them.
 */
final class Storages {

	const EXTENSIONS = array( 'fmw', 'wpress' );

	/**
	 * Storage settings.
	 *
	 * @return CollectionStore
	 */
	public static function store(): CollectionStore {
		return new CollectionStore( fmwp_storage_path() . '/storages.json', 'storages' );
	}

	/**
	 * One storage.
	 *
	 * @param string $id ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::store()->get( $id );
	}

	/**
	 * A client for a storage.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return S3Client
	 * @throws RemoteException When the saved secret key cannot be read.
	 */
	public static function client( array $storage ): S3Client {
		$secret = Secrets::open( $storage['secret'] ?? null );
		if ( null === $secret ) {
			throw new RemoteException( sprintf( 'The secret key of "%s" cannot be read (the site\'s salts changed). Enter it again.', (string) ( $storage['name'] ?? '' ) ) );
		}
		return new S3Client(
			array(
				'endpoint'   => (string) $storage['endpoint'],
				'region'     => (string) $storage['region'],
				'bucket'     => (string) $storage['bucket'],
				'access_key' => (string) $storage['access_key'],
				'secret_key' => $secret,
				'path_style' => ! empty( $storage['path_style'] ),
			)
		);
	}

	/**
	 * Headers for new objects (storage class).
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return array<string,string>
	 */
	public static function upload_headers( array $storage ): array {
		return '' !== (string) ( $storage['storage_class'] ?? '' ) ? array( 'x-amz-storage-class' => (string) $storage['storage_class'] ) : array();
	}

	/**
	 * Backups (.fmw, .wpress) in a storage's folder, newest first.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return array<int,array{name:string,key:string,size:int,mtime:int}>
	 */
	public static function backups( array $storage ): array {
		$prefix = '' !== (string) $storage['prefix'] ? $storage['prefix'] . '/' : '';
		$items  = array();
		foreach ( self::client( $storage )->list( $prefix ) as $object ) {
			$name = substr( $object['key'], strlen( $prefix ) );
			if ( '' !== $name && in_array( strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true ) ) {
				$items[] = $object + array( 'name' => $name );
			}
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$order = $b['mtime'] <=> $a['mtime'];
				return 0 !== $order ? $order : strcmp( $a['name'], $b['name'] );
			}
		);
		return $items;
	}

	/**
	 * Options of a job that uploads an existing backup.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @param string              $path    Backup file.
	 * @return array<string,mixed>
	 */
	public static function upload_job( array $storage, string $path ): array {
		return array(
			'upload_path' => $path,
			'remote'      => array(
				'storage'      => (string) $storage['id'],
				'delete_local' => false,
			),
		);
	}

	/**
	 * Options of a job that downloads a backup into the backups folder.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @param string              $name    Backup file name in the storage folder.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the name is not a backup.
	 */
	public static function download_job( array $storage, string $name ): array {
		if ( basename( $name ) !== $name || '' === $name ) {
			throw new \InvalidArgumentException( 'Give the backup file name, without a folder.' );
		}
		$dir  = fmwp_backups_path();
		$want = Uploads::sanitize_name( $name );
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$file = Backups::unique_name( $want );
			// Reserve the name: two downloads of the same backup must not share one .partial file.
			$reserved = file_exists( $dir . '/' . $file ) ? false : @fopen( $dir . '/' . $file . '.partial', 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Fails when taken.
			if ( false !== $reserved ) {
				fclose( $reserved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen().
				break;
			}
			$want = preg_replace( '/(\.[a-z]+)$/i', '-' . ( $attempt + 2 ) . '$1', Uploads::sanitize_name( $name ) );
		}
		return array(
			'archive_dir'  => $dir,
			'archive_name' => $file,
			'remote'       => array(
				'storage' => (string) $storage['id'],
				'key'     => StorageOptions::key( $storage, $name ),
			),
		);
	}

	/**
	 * Checks that the storage can be written, read, listed and cleaned up.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return string What was checked.
	 * @throws RemoteException When a check fails.
	 */
	public static function test( array $storage ): string {
		$client = self::client( $storage );
		$key    = StorageOptions::key( $storage, '.fmw-connection-test-' . bin2hex( random_bytes( 4 ) ) );
		$body   = 'Founders Migration Website connection test ' . gmdate( 'c' );
		$client->put_string( $key, $body, self::upload_headers( $storage ) );
		try {
			if ( $client->get_string( $key ) !== $body ) {
				throw new RemoteException( 'The test file came back different from what was written.' );
			}
			$client->list( '' !== (string) $storage['prefix'] ? $storage['prefix'] . '/' : '', 1 );
		} finally {
			$client->delete( $key );
		}
		return 'write, read, list and delete work';
	}
}
