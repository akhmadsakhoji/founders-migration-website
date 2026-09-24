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

defined( 'ABSPATH' ) || defined( 'FMWP_TESTS' ) || exit;

/**
 * One kind of cloud storage (S3-compatible, Google Drive, ...), as the upload and download jobs see it.
 *
 * A backup is found by its file name and addressed by a key the driver
 * chooses (the object key in S3, the file ID in Google Drive). Upload state
 * is a plain array the job checkpoints; every method may throw
 * RemoteException, with the code UploadExpired when an unfinished upload is
 * no longer known and has to start again.
 */
interface Driver {

	/**
	 * Writes, reads, lists and deletes a small test file.
	 *
	 * @return string What was checked.
	 */
	public function test(): string;

	/**
	 * Backups (.fmw, .wpress) in the storage folder, newest first.
	 *
	 * @return array<int,array{name:string,key:string,size:int,mtime:int}>
	 */
	public function backups(): array;

	/**
	 * A backup by file name, or null.
	 *
	 * @param string $name File name.
	 * @return array{name:string,key:string,size:int,mtime:int,etag:string}|null
	 */
	public function find( string $name ): ?array;

	/**
	 * Starts an upload.
	 *
	 * @param string $name File name.
	 * @param int    $size Bytes.
	 * @return array<string,mixed> State, with offset 0.
	 */
	public function upload_open( string $name, int $size ): array;

	/**
	 * Brings the state in line with what the storage really has (after a crash).
	 *
	 * @param array<string,mixed> $state State.
	 * @param int                 $size  Bytes.
	 * @return array<string,mixed>
	 */
	public function upload_sync( array $state, int $size ): array;

	/**
	 * Length of the next chunk: $wanted adjusted to the storage's rules.
	 *
	 * @param array<string,mixed> $state  State.
	 * @param int                 $size   Bytes in total.
	 * @param int                 $wanted Bytes the caller would like to send.
	 * @return int
	 */
	public function chunk_length( array $state, int $size, int $wanted ): int;

	/**
	 * Sends bytes offset..offset+length-1.
	 *
	 * @param array<string,mixed> $state  State.
	 * @param string              $path   File.
	 * @param int                 $length Bytes.
	 * @param int                 $size   Bytes in total.
	 * @return array<string,mixed> State with the new offset.
	 */
	public function upload_chunk( array $state, string $path, int $length, int $size ): array;

	/**
	 * Finishes the upload (idempotent) and checks the size.
	 *
	 * @param array<string,mixed> $state State.
	 * @param int                 $size  Bytes.
	 * @return array{key:string,size:int}
	 */
	public function upload_close( array $state, int $size ): array;

	/**
	 * Abandons an unfinished upload (best effort).
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	public function upload_abort( array $state ): void;

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
	public function download_range( string $key, int $from, int $to, $sink, string $etag ): int;

	/**
	 * Deletes a backup (no error when it is already gone).
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function delete( string $key ): void;
}
