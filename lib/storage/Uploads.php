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

// phpcs:disable WordPress.WP.AlternativeFunctions -- Multi-GB uploads are appended with native file calls.

/**
 * Resumable, chunked uploads of backup files.
 *
 * The browser sends the file in chunks, each with the offset it starts at.
 * A chunk is accepted only at the current end of the partial file, so a
 * retried or duplicated chunk can never corrupt it; on a mismatch the
 * client is told the real offset and continues from there. The upload id is
 * derived from the user, file name and size, so choosing the same file
 * again after a dropped connection or a closed tab resumes it.
 *
 * Layout: <root>/<id>/meta.json and <root>/<id>/data.part.
 */
final class Uploads {

	const EXTENSIONS = array( 'fmw', 'wpress' );
	const MAX_NAME   = 180;
	const STALE_DAYS = 7;

	/**
	 * Uploads folder.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Constructor.
	 *
	 * @param string $root Folder (created when needed), for example fmw-storage/uploads.
	 */
	public function __construct( string $root ) {
		$this->root = rtrim( $root, '/' );
	}

	/**
	 * Starts an upload, or finds the partial upload of the same file.
	 *
	 * @param string $name    File name from the browser.
	 * @param int    $size    File size in bytes.
	 * @param int    $user_id Uploading user.
	 * @return array{id:string,name:string,size:int,offset:int,user:int}
	 * @throws \InvalidArgumentException On an unsupported name or size.
	 * @throws \RuntimeException When the folder cannot be written.
	 */
	public function open( string $name, int $size, int $user_id ): array {
		$name = self::sanitize_name( $name );
		if ( $size <= 0 ) {
			throw new \InvalidArgumentException( 'The file is empty.' );
		}
		$id  = substr( hash( 'sha256', $user_id . '|' . $name . '|' . $size ), 0, 32 );
		$dir = $this->root . '/' . $id;
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( sprintf( 'Cannot create %s.', $dir ) );
		}
		if ( ! is_file( $dir . '/meta.json' ) ) {
			$meta = array(
				'name'    => $name,
				'size'    => $size,
				'user'    => $user_id,
				'created' => time(),
			);
			if ( false === file_put_contents( $dir . '/meta.json', (string) json_encode( $meta ) ) || false === file_put_contents( $dir . '/data.part', '', FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Internal file.
				throw new \RuntimeException( sprintf( 'Cannot write in %s.', $dir ) );
			}
		}
		return $this->status( $id );
	}

	/**
	 * State of an upload.
	 *
	 * @param string $id Upload id.
	 * @return array{id:string,name:string,size:int,offset:int,user:int}
	 * @throws \InvalidArgumentException When it does not exist.
	 */
	public function status( string $id ): array {
		$dir  = $this->dir( $id );
		$meta = json_decode( (string) @file_get_contents( $dir . '/meta.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file handled below.
		if ( ! is_array( $meta ) || ! isset( $meta['name'], $meta['size'] ) ) {
			throw new \InvalidArgumentException( 'Unknown upload.' );
		}
		clearstatcache( true, $dir . '/data.part' );
		return array(
			'id'     => $id,
			'name'   => (string) $meta['name'],
			'size'   => (int) $meta['size'],
			'offset' => (int) @filesize( $dir . '/data.part' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 0 when missing.
			'user'   => (int) ( $meta['user'] ?? 0 ),
		);
	}

	/**
	 * Appends a chunk that starts at $offset.
	 *
	 * @param string $id     Upload id.
	 * @param int    $offset Where the chunk starts.
	 * @param string $data   Chunk.
	 * @return int The new offset.
	 * @throws UploadOffsetException When $offset is not the current end (carries the real offset).
	 * @throws \InvalidArgumentException When the chunk goes past the announced size.
	 * @throws \RuntimeException On a write error.
	 */
	public function append( string $id, int $offset, string $data ): int {
		$status = $this->status( $id );
		$path   = $this->dir( $id ) . '/data.part';
		$handle = fopen( $path, 'ab' );
		if ( false === $handle || ! flock( $handle, LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot write the upload.' );
		}
		try {
			clearstatcache( true, $path );
			$current = (int) filesize( $path );
			if ( $offset !== $current ) {
				throw new UploadOffsetException( $current );
			}
			if ( $offset + strlen( $data ) > $status['size'] ) {
				throw new \InvalidArgumentException( 'The chunk goes past the end of the file.' );
			}
			if ( '' !== $data && strlen( $data ) !== fwrite( $handle, $data ) ) {
				ftruncate( $handle, $current );
				throw new \RuntimeException( 'Write failed. The disk may be full.' );
			}
			fflush( $handle );
			return $current + strlen( $data );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * Moves a complete upload into the backups folder.
	 *
	 * @param string $id        Upload id.
	 * @param string $backups   Backups folder.
	 * @param string $file_name Name to use there (unique).
	 * @return string Path of the backup.
	 * @throws \InvalidArgumentException When the upload is incomplete.
	 * @throws \RuntimeException When it cannot be moved.
	 */
	public function complete( string $id, string $backups, string $file_name ): string {
		$status = $this->status( $id );
		if ( $status['offset'] !== $status['size'] ) {
			throw new \InvalidArgumentException( sprintf( 'The upload is incomplete (%d of %d bytes).', $status['offset'], $status['size'] ) );
		}
		$target  = rtrim( $backups, '/' ) . '/' . $file_name;
		$reserve = @fopen( $target, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fails when the name is taken; reported below.
		if ( false === $reserve ) {
			throw new \RuntimeException( sprintf( '%s already exists.', $file_name ) );
		}
		fclose( $reserve );
		if ( ! rename( $this->dir( $id ) . '/data.part', $target ) ) {
			unlink( $target );
			throw new \RuntimeException( sprintf( 'Cannot move the upload to %s.', $file_name ) );
		}
		$this->discard( $id );
		return $target;
	}

	/**
	 * Deletes an upload.
	 *
	 * @param string $id Upload id.
	 * @return void
	 */
	public function discard( string $id ): void {
		$dir = $this->dir( $id );
		foreach ( array( 'data.part', 'meta.json' ) as $file ) {
			if ( is_file( $dir . '/' . $file ) ) {
				unlink( $dir . '/' . $file );
			}
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}

	/**
	 * Deletes uploads nobody touched for a while.
	 *
	 * @param int $now Current time.
	 * @return int Number removed.
	 */
	public function remove_stale( int $now ): int {
		$removed = 0;
		foreach ( (array) glob( $this->root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$id    = basename( (string) $dir );
			$mtime = (int) @filemtime( $dir . '/data.part' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 0 when missing.
			if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $id ) && $now - $mtime > self::STALE_DAYS * 86400 ) {
				$this->discard( $id );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Safe backup file name: letters, digits, dot, dash and underscore; .fmw or .wpress.
	 *
	 * @param string $name Name from the browser.
	 * @return string
	 * @throws \InvalidArgumentException When the extension is not supported.
	 */
	public static function sanitize_name( string $name ): string {
		$name      = basename( str_replace( '\\', '/', $name ) );
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
			throw new \InvalidArgumentException( 'Only .fmw and .wpress backups can be imported.' );
		}
		$base = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', substr( $name, 0, - strlen( $extension ) - 1 ) );
		$base = trim( (string) preg_replace( '/-{2,}/', '-', $base ), '.-' );
		$base = substr( '' === $base ? 'backup' : $base, 0, self::MAX_NAME );
		return $base . '.' . $extension;
	}

	/**
	 * Folder of an upload id.
	 *
	 * @param string $id Upload id.
	 * @return string
	 * @throws \InvalidArgumentException On a malformed id.
	 */
	private function dir( string $id ): string {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			throw new \InvalidArgumentException( 'Unknown upload.' );
		}
		return $this->root . '/' . $id;
	}
}
