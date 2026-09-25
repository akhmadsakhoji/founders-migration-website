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

defined( 'ABSPATH' ) || exit;

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
	 * The driver of a storage.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return Driver
	 * @throws RemoteException When the saved keys cannot be read, or Google Drive is not connected yet.
	 */
	public static function driver( array $storage ): Driver {
		if ( 'gdrive' === ( $storage['provider'] ?? '' ) ) {
			return new DriveDriver( $storage );
		}
		return new S3Driver( $storage, self::client( $storage ) );
	}

	/**
	 * An S3 client for an S3-compatible storage.
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
	 * Saves edited settings. Google sign-in data written meanwhile (a refreshed
	 * token, the folder found on first use) is kept unless the edit dropped it
	 * on purpose (another client ID or folder).
	 *
	 * @param array<string,mixed> $storage Storage built from the edit.
	 * @return array<string,mixed> What was saved.
	 */
	public static function save_settings( array $storage ): array {
		$saved = self::store()->update(
			static function ( array &$data ) use ( $storage ): array {
				$current = $data['records'][ $storage['id'] ] ?? null;
				if ( is_array( $current ) && 'gdrive' === $storage['provider'] ) {
					if ( ( $current['client_id'] ?? '' ) === $storage['client_id'] ) {
						foreach ( array( 'refresh', 'access', 'account', 'connected_at' ) as $field ) {
							$storage[ $field ] = $current[ $field ] ?? $storage[ $field ];
						}
					}
					if ( ( $current['prefix'] ?? '' ) === $storage['prefix'] ) {
						$storage['folder_id']   = $current['folder_id'] ?? $storage['folder_id'];
						$storage['folder_path'] = $current['folder_path'] ?? $storage['folder_path'];
					}
				}
				$data['records'][ $storage['id'] ] = $storage;
				return $storage;
			}
		);
		return (array) $saved;
	}

	/**
	 * Backups in a storage's folder, newest first.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return array<int,array{name:string,key:string,size:int,mtime:int}>
	 */
	public static function backups( array $storage ): array {
		return self::driver( $storage )->backups();
	}

	/**
	 * Checks that the storage can be written, read, listed and cleaned up.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @return string What was checked.
	 */
	public static function test( array $storage ): string {
		return self::driver( $storage )->test();
	}

	/**
	 * Deletes a backup from a storage by file name.
	 *
	 * @param array<string,mixed> $storage Storage.
	 * @param string              $name    File name.
	 * @return bool Whether it was there.
	 */
	public static function delete_backup( array $storage, string $name ): bool {
		$driver = self::driver( $storage );
		$found  = $driver->find( $name );
		if ( null === $found ) {
			return false;
		}
		$driver->delete( $found['key'] );
		return true;
	}

	/**
	 * Whether a file name is a backup this plugin can restore.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_backup_name( string $name ): bool {
		return false === strpos( $name, '/' ) && in_array( strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	/**
	 * Sorts backups newest first (then by name).
	 *
	 * @param array<int,array<string,mixed>> $items Backups with mtime and name.
	 * @return array<int,array<string,mixed>>
	 */
	public static function newest_first( array $items ): array {
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$order = $b['mtime'] <=> $a['mtime'];
				return 0 !== $order ? $order : strcmp( (string) $a['name'], (string) $b['name'] );
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
		$file = $want;
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$file = Backups::unique_name( $want );
			// Reserve the name: two downloads of the same backup must not share one .partial file.
			$reserved = file_exists( $dir . '/' . $file ) ? false : @fopen( $dir . '/' . $file . '.partial', 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Fails when taken.
			if ( false !== $reserved ) {
				fclose( $reserved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen().
				break;
			}
			$want = (string) preg_replace( '/(\.[a-z]+)$/i', '-' . ( $attempt + 2 ) . '$1', Uploads::sanitize_name( $name ) );
		}
		return array(
			'archive_dir'  => $dir,
			'archive_name' => $file,
			'remote'       => array(
				'storage' => (string) $storage['id'],
				'name'    => $name,
			),
		);
	}
}
