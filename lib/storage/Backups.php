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

/**
 * Backups stored in the backups folder.
 */
final class Backups {

	const EXTENSIONS = array( 'fmw', 'wpress' );

	/**
	 * Backups, newest first: the FMW backups folder, plus .wpress files in wp-content/ai1wm-backups.
	 *
	 * The ai1wm-backups folder is only read (restore, download); FMW never deletes from it.
	 *
	 * @return array<int,array{name:string,path:string,size:int,mtime:int,type:string,source:string}>
	 */
	public static function all(): array {
		$items = array();
		$seen  = array();
		foreach ( self::folders() as $source => $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( new \DirectoryIterator( $dir ) as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$extension = strtolower( $file->getExtension() );
				if ( ! in_array( $extension, 'fmw' === $source ? self::EXTENSIONS : array( 'wpress' ), true ) || isset( $seen[ $file->getFilename() ] ) ) {
					continue;
				}
				$seen[ $file->getFilename() ] = true;
				$items[]                      = array(
					'name'   => $file->getFilename(),
					'path'   => wp_normalize_path( $file->getPathname() ),
					'size'   => (int) $file->getSize(),
					'mtime'  => (int) $file->getMTime(),
					'type'   => $extension,
					'source' => $source,
				);
			}
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $b['mtime'] <=> $a['mtime'];
			}
		);

		return $items;
	}

	/**
	 * Folders searched for backups, by source.
	 *
	 * @return array<string,string>
	 */
	public static function folders(): array {
		return array(
			'fmw'   => fmwp_backups_path(),
			'ai1wm' => untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/ai1wm-backups',
		);
	}

	/**
	 * A backup by file name, refusing anything that is not listed by all().
	 *
	 * @param string $name File name.
	 * @return array{name:string,path:string,size:int,mtime:int,type:string,source:string}|null
	 */
	public static function get( string $name ): ?array {
		if ( basename( $name ) !== $name || '' === $name || '.' === $name[0] ) {
			return null;
		}
		foreach ( self::all() as $backup ) {
			if ( $backup['name'] === $name ) {
				return $backup;
			}
		}
		return null;
	}

	/**
	 * Resolves a backup by file name, refusing anything outside the backup folders.
	 *
	 * @param string $name File name as listed by all().
	 * @return string|null Absolute path, or null when not found.
	 */
	public static function find( string $name ): ?string {
		$backup = self::get( $name );
		return null === $backup ? null : $backup['path'];
	}

	/**
	 * Deletes a backup from the FMW backups folder.
	 *
	 * @param string $name File name.
	 * @return bool False when it is not there, belongs to All-in-One WP Migration, or cannot be deleted.
	 */
	public static function delete( string $name ): bool {
		$backup = self::get( $name );
		if ( null === $backup || 'fmw' !== $backup['source'] ) {
			return false;
		}
		wp_delete_file( $backup['path'] );
		return ! file_exists( $backup['path'] );
	}

	/**
	 * A file name in the backups folder that is not taken yet, based on $name.
	 *
	 * @param string $name Wanted name (already sanitized).
	 * @return string
	 */
	public static function unique_name( string $name ): string {
		$dir       = fmwp_backups_path();
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$base      = substr( $name, 0, - strlen( $extension ) - 1 );
		$candidate = $name;
		$i         = 2;
		while ( file_exists( $dir . '/' . $candidate ) ) {
			$candidate = $base . '-' . $i . '.' . $extension;
			++$i;
		}
		return $candidate;
	}
}
