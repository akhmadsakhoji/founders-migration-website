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

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * Backups stored in the backups folder.
 */
final class Backups {

	const EXTENSIONS = array( 'fmw', 'wpress' );

	/**
	 * Backups, newest first.
	 *
	 * @return array<int,array{name:string,path:string,size:int,mtime:int,type:string}>
	 */
	public static function all(): array {
		$dir = fmwp_backups_path();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$items = array();
		foreach ( new \DirectoryIterator( $dir ) as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$extension = strtolower( $file->getExtension() );
			if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
				continue;
			}
			$items[] = array(
				'name'  => $file->getFilename(),
				'path'  => wp_normalize_path( $file->getPathname() ),
				'size'  => (int) $file->getSize(),
				'mtime' => (int) $file->getMTime(),
				'type'  => $extension,
			);
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
	 * Resolves a backup by file name, refusing anything outside the backups folder.
	 *
	 * @param string $name File name as listed by all().
	 * @return string|null Absolute path, or null when not found.
	 */
	public static function find( string $name ): ?string {
		if ( basename( $name ) !== $name || '' === $name || '.' === $name[0] ) {
			return null;
		}
		foreach ( self::all() as $backup ) {
			if ( $backup['name'] === $name ) {
				return $backup['path'];
			}
		}
		return null;
	}
}
